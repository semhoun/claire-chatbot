<?php

declare(strict_types=1);

namespace App\Job\Web;

use App\Brain\Agent;
use App\Brain\BrainRegistry;
use App\Brain\Summary;
use App\Services\ChatStreamPublisher;
use App\Services\Queue\QueueDoer;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as Logger;
use Slim\Views\Twig;

/**
 * Handles the processing of streaming chat messages in a web-based real-time chat system.
 *
 * This class is utilized to process user messages, handle chat state, stream agent responses,
 * manage message attachments, and publish updates to a chat stream through a publisher service.
 *
 * Responsibilities include:
 * - Initializing the context for chat processing with user, session, and message data.
 * - Streaming agent responses to the user while managing asynchronous chunks of text or tools.
 * - Handling formatting and publishing of updates for tool usage and user-facing text chunks.
 * - Managing error states and ensuring appropriate feedback is provided to the user when issues occur.
 * - Finalizing the chat stream once all processing is complete.
 *
 * Implements the `QueueDoer` interface to integrate with a queue job execution system.
 */
final class NewMessageJob implements QueueDoer
{
    private string $streamedText = '';

    private int $nbPublishedChunks = 0;

    private string $userMessage;

    private string $threadId;

    private string $sessionId;

    private string $messageId;

    private InMemorySession $inMemorySession;

    private ?Agent $agent = null;

    /** @var array<int, string>|null */
    private ?array $attachments = null;

    /** @var array<string, array<string, mixed>> */
    private array $toolsCall = [];

    public function __construct(
        private readonly Logger $logger,
        private readonly Twig $twig,
        private readonly BrainRegistry $brainRegistry,
        private readonly ChatStreamPublisher $chatStreamPublisher,
        private readonly Connection $connection,
        private readonly Settings $settings
    ) {
    }

    public static function make(ContainerInterface $container): self
    {
        return $container->get(self::class);
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        try {
            $this->initContext($payload);
            $this->processChatStream();
            $this->manageSummary();
        } catch (\Throwable $throwable) {
            $this->handleChatError($throwable);
        }
    }

    /**
     * Initializes the context using the given payload.
     *
     * @param array<string, mixed> $payload The input data containing the required fields
     *                                      such as 'message', 'threadId', 'sessionId',
     *                                      'session', 'brainAvatar', and optional 'attachments'.
     *                                      - 'message': string containing the user's message (required, non-empty).
     *                                      - 'threadId': string identifying the chat (required, non-empty).
     *                                      - 'sessionId': string identifying the session (required, non-empty).
     *                                      - 'session': an array or data structure used to initialize the session.
     *                                      - 'brainAvatar': a string used to fetch the appropriate agent.
     *                                      - 'attachments': an array containing 'uploadedFiles' and/or 'fileIds'.
     *
     * @throws \InvalidArgumentException If 'message', 'threadId', or 'sessionId' are missing or empty.
     */
    private function initContext(array $payload): void
    {
        $this->messageId = $payload['messageId'] ?? uniqid('assistant-message-', true);

        $this->userMessage = trim((string) ($payload['message'] ?? ''));
        if ($this->userMessage === '') {
            throw new \InvalidArgumentException('User message cannot be empty');
        }

        $this->threadId = (string) ($payload['threadId'] ?? '');
        if ($this->threadId === '') {
            throw new \InvalidArgumentException('Thread ID cannot be empty');
        }

        $this->sessionId = (string) ($payload['sessionId'] ?? '');
        if ($this->sessionId === '') {
            throw new \InvalidArgumentException('Session ID cannot be empty');
        }

        $this->inMemorySession = new InMemorySession($payload['session']);

        $brainAvatar = $this->inMemorySession->get('brain_avatar');
        $this->agent = $this->brainRegistry->get($brainAvatar, $this->inMemorySession, $this->threadId);

        if (! empty($payload['attachments'])) {
            $this->attachments = array_merge($payload['attachments']['uploadedFiles'] ?? [], $payload['attachments']['fileIds'] ?? []);
        }
    }

    private function processChatStream(): void
    {
        $this->publishStartMessages();

        $userMessage = new UserMessage($this->userMessage);
        $userMessage->addMetadata('timestamp', new DateTimeImmutable()->format(DateTimeInterface::ATOM));
        $this->addAttachments($userMessage);

        $agentHandler = $this->agent->stream($userMessage);

        foreach ($agentHandler->events() as $chunk) {
            $this->publishPlaceHolder();
            $this->processChunk($chunk);
            $this->nbPublishedChunks++;
        }

        $finalText = $agentHandler->getMessage()->getContent();
        if ($finalText !== '' && $finalText !== null) {
            $this->publishContent($finalText);
        } else {
            $this->publishContent($this->streamedText);
        }

        $this->publishDoneMessages();
    }

    private function processChunk(mixed $chunk): void
    {
        if ($chunk === null) {
            return;
        }

        if ($chunk instanceof ToolCallChunk || $chunk instanceof ToolResultChunk) {
            $this->processToolChunk($chunk);
            return;
        }

        if ($chunk instanceof ReasoningChunk) {
            return;
        }

        if ($chunk instanceof TextChunk) {
            $this->processTextChunk($chunk);
            return;
        }

        $this->logger->debug('Chunk type not handled: ' . $chunk::class);
    }

    private function processToolChunk(ToolCallChunk|ToolResultChunk $chunk): void
    {
        $tool = $chunk->tool;
        $id = $tool->getCallId();
        $toolData = [
            'id' => $id,
            'name' => $tool->getName(),
            'inputs' => [],
            'running' => $chunk instanceof ToolCallChunk,
            'result' => $chunk instanceof ToolResultChunk ? $tool->getResult() : null,
        ];
        foreach ($tool->getInputs() as $name => $val) {
            $toolData['inputs'][] = [
                'name' => $name,
                'value' => $val,
            ];
        }

        $this->toolsCall[$id] = $toolData;

        $toolsHtml = $this->twig->fetch('partials/toolscall.twig', [
            'toolsCall' => $this->toolsCall,
        ]);
        $this->chatStreamPublisher->publish($this->sessionId, 'chat.tool.update', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
            'html' => $toolsHtml,
        ]);
    }

    private function processTextChunk(ReasoningChunk|TextChunk $chunk): void
    {
        if ($chunk->content === '') {
            return;
        }

        $this->streamedText .= $chunk->content;

        $html = $this->twig->fetch('partials/md.twig', [
            'message' => $this->streamedText,
            'streaming_placeholder_images' => true,
        ]);

        $this->chatStreamPublisher->publish($this->sessionId, 'chat.assistant.update', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
            'html' => $html,
        ]);
    }

    private function publishStartMessages(): void
    {
        $this->chatStreamPublisher->publish($this->sessionId, 'chat.assistant.start', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
        ]);
    }

    private function publishDoneMessages(): void
    {
        $this->chatStreamPublisher->publish($this->sessionId, 'chat.assistant.done', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
        ]);
    }

    private function publishPlaceHolder(): void
    {
        if ($this->nbPublishedChunks % 10 !== 0) {
            return;
        }

        $placeholderHtml = $this->twig->fetch('partials/message.twig', [
            'message' => ['id' => $this->messageId, 'message' => ''],
            'time' => new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            'sent' => false,
        ]);
        $this->chatStreamPublisher->publish($this->sessionId, 'chat.assistant.placeholder', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
            'html' => $placeholderHtml,
        ]);
    }

    private function publishContent(string $content): void
    {
        $html = $this->twig->fetch('partials/md.twig', [
            'message' => $content,
            'streaming_placeholder_images' => false,
        ]);

        $this->chatStreamPublisher->publish($this->sessionId, 'chat.assistant.update', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
            'html' => $html,
        ]);
    }

    private function handleChatError(\Throwable $throwable): void
    {
        $this->logger->error('Web chat job failed', [
            'exception' => $throwable,
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
        ]);
        $this->chatStreamPublisher->publish($this->sessionId, 'chat.error', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => $this->messageId,
            'message' => 'Désolé, une erreur est survenue lors du traitement de votre message.',
        ]);
    }

    private function addAttachments(UserMessage $userMessage): void
    {
        if (! is_array($this->attachments)) {
            return;
        }

        foreach ($this->attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $content = (string) ($attachment['content'] ?? '');
            if ($content === '') {
                continue;
            }

            $userMessage->addContent(new \NeuronAI\Chat\Messages\ContentBlocks\FileContent(
                $content,
                \NeuronAI\Chat\Enums\SourceType::BASE64,
                (string) ($attachment['mimeType'] ?? 'application/octet-stream'),
                (string) ($attachment['filename'] ?? 'file'),
            ));
        }
    }

    private function manageSummary(): void
    {
        $summary = new Summary($this->connection, $this->settings, $this->inMemorySession, $this->threadId);
        $chatHistory = $summary->getChatHistory();

        if (! ($chatHistory instanceof \App\Brain\ChatHistory\UserChatHistory)) {
            $this->logger->error('Summary not available for non-user chat history');
            return;
        }

        $messages = $chatHistory->getDisplayMessages();
        $longTermMemoryEnabled = $this->inMemorySession->get(
            \App\Brain\LongTermMemory::SESSION_KEY,
            false
        ) === true;

        if (! $longTermMemoryEnabled
            && $messages !== [] && count($messages) >= $this->settings->get('llm.summary.minMessages')
            && count($messages) <= $this->settings->get('llm.summary.maxMessages')
            && $chatHistory->getSummary() !== null && $chatHistory->getSummary() !== '') {
            return;
        }

        $summary->generateAndPersist();
    }
}
