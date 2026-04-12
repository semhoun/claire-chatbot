<?php

declare(strict_types=1);

namespace App\Queue;

use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\Summary;
use App\Services\ChatStreamPublisher;
use App\Services\Session\InMemorySession;
use App\Services\Session\SessionInterface;
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

final readonly class WebChatMessageJob implements QueueDoer
{
    public function __construct(
        private Logger $logger,
        private Twig $twig,
        private BrainRegistry $brainRegistry,
        private ChatStreamPublisher $chatStreamPublisher,
        private Connection $connection,
        private Settings $settings,
    ) {
    }

    public static function make(ContainerInterface $container): self
    {
        return $container->get(self::class);
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        $context = $this->createJobContext($payload);
        if ($context === null) {
            return;
        }

        try {
            $this->processChatStream($context);
            $this->finalizeChat($context);
            $this->manageSummary($context->inMemorySession);
        } catch (\Throwable $throwable) {
            $this->handleChatError($throwable, $context);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createJobContext(array $payload): ?JobContext
    {
        $params = $this->extractPayloadParams($payload);

        if (! $this->isValidPayload($params)) {
            return null;
        }

        $inMemorySession = $this->createInMemorySession($params['chatId'], $params['sessionValues']);

        return new JobContext(
            chatId: $params['chatId'],
            sessionId: $params['sessionId'],
            messageId: uniqid('assistant-', true),
            messageArticleId: $this->resolveMessageArticleId($params['messageArticleId']),
            timestamp: new DateTimeImmutable()->format(DateTimeInterface::ATOM),
            inMemorySession: $inMemorySession,
            agent: $this->brainRegistry->get($params['brainAvatar'], $inMemorySession),
            userMessage: $this->createUserMessage($params['messageText'], $params['attachments']),
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{messageText:string, chatId:string, sessionId:string, sessionValues:mixed, brainAvatar:string, messageArticleId:string, attachments:mixed}
     */
    private function extractPayloadParams(array $payload): array
    {
        return [
            'messageText' => trim((string) ($payload['message'] ?? '')),
            'chatId' => (string) ($payload['chatId'] ?? ''),
            'sessionId' => (string) ($payload['sessionId'] ?? ''),
            'sessionValues' => $payload['session'] ?? [],
            'brainAvatar' => (string) ($payload['brainAvatar'] ?? ''),
            'messageArticleId' => trim((string) ($payload['messageArticleId'] ?? '')),
            'attachments' => $payload['attachments'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function isValidPayload(array $params): bool
    {
        return $params['messageText'] !== ''
            && $params['chatId'] !== ''
            && $params['sessionId'] !== ''
            && is_array($params['sessionValues']);
    }

    /**
     * @param array<string, mixed> $sessionValues
     */
    private function createInMemorySession(string $chatId, array $sessionValues): InMemorySession
    {
        $session = new InMemorySession($sessionValues);
        $session->set('chatId', $chatId);

        return $session;
    }

    private function resolveMessageArticleId(string $messageArticleId): string
    {
        return $messageArticleId !== '' ? $messageArticleId : uniqid('assistant-message-', true);
    }

    /**
     * @param array<string, mixed>|null $attachments
     */
    private function createUserMessage(string $messageText, ?array $attachments): UserMessage
    {
        $userMessage = new UserMessage($messageText);
        $userMessage->addMetadata('timestamp', new DateTimeImmutable()->format(DateTimeInterface::ATOM));
        $this->addAttachments($userMessage, $attachments);

        return $userMessage;
    }

    private function processChatStream(JobContext $context): void
    {
        $agentHandler = $context->agent->stream($context->userMessage);
        $streamState = new StreamState();

        foreach ($agentHandler->events() as $chunk) {
            $this->processChunk($chunk, $context, $streamState);
        }

        $context->setFinalContent($agentHandler->getMessage()->getContent());
    }

    private function processChunk(mixed $chunk, JobContext $context, StreamState $state): void
    {
        if ($chunk instanceof ToolCallChunk || $chunk instanceof ToolResultChunk) {
            $this->processToolChunk($chunk, $context, $state);
            return;
        }

        if ($chunk instanceof ReasoningChunk || $chunk instanceof TextChunk) {
            $this->processTextChunk($chunk, $context, $state);
        }
    }

    private function processToolChunk(ToolCallChunk|ToolResultChunk $chunk, JobContext $context, StreamState $state): void
    {
        if (! $state->isAssistantStarted()) {
            $this->publishAssistantStart($context, '');
            $state->setAssistantStarted(true);
        }

        if ($state->getToolCallId() === null) {
            $state->setToolCallId(uniqid('tool-', true));
        }
        $state->appendToolText($this->formatToolChunk($chunk));

        if (! $state->isToolPlaceholderPublished()) {
            $this->publishPlaceholder($context, $state->getToolCallId(), $state->getToolText());
            $state->setToolPlaceholderPublished(true);
        }

        $this->chatStreamPublisher->publish($context->sessionId, 'tool.update', [
            'chatId' => $context->chatId,
            'sessionId' => $context->sessionId,
            'messageId' => $context->messageId,
            'messageArticleId' => $context->messageArticleId,
            'toolCallId' => $state->getToolCallId(),
            'html' => $state->getToolText(),
        ]);
    }

    private function processTextChunk(ReasoningChunk|TextChunk $chunk, JobContext $context, StreamState $state): void
    {
        if (! $state->isAssistantStarted()) {
            $this->publishAssistantStart($context, '');
            $state->setAssistantStarted(true);
        }

        $state->appendStreamedText($chunk->content);
        $html = $this->twig->fetch('partials/md.twig', [
            'message' => $state->getStreamedText(),
            'streaming_placeholder_images' => true,
        ]);

        $this->chatStreamPublisher->publish($context->sessionId, 'message.assistant.delta', [
            'chatId' => $context->chatId,
            'sessionId' => $context->sessionId,
            'messageId' => $context->messageId,
            'messageArticleId' => $context->messageArticleId,
            'html' => $html,
        ]);
    }

    private function publishAssistantStart(JobContext $context, string $message): void
    {
        $placeholderHtml = $this->twig->fetch('partials/message.twig', [
            'message' => $message,
            'time' => $context->timestamp,
            'sent' => false,
            'messageArticleId' => $context->messageArticleId,
            'streamId' => $context->messageId,
            'toolCallId' => null,
            'toolCall' => null,
        ]);

        $this->chatStreamPublisher->publish($context->sessionId, 'message.assistant.start', [
            'chatId' => $context->chatId,
            'sessionId' => $context->sessionId,
            'messageId' => $context->messageId,
            'messageArticleId' => $context->messageArticleId,
        ]);
        $this->chatStreamPublisher->publish($context->sessionId, 'message.assistant.placeholder', [
            'chatId' => $context->chatId,
            'sessionId' => $context->sessionId,
            'messageId' => $context->messageId,
            'messageArticleId' => $context->messageArticleId,
            'html' => $placeholderHtml,
        ]);
    }

    private function publishPlaceholder(JobContext $context, string $toolCallId, string $toolText): void
    {
        $placeholderHtml = $this->twig->fetch('partials/message.twig', [
            'message' => '',
            'time' => $context->timestamp,
            'sent' => false,
            'messageArticleId' => $context->messageArticleId,
            'streamId' => $context->messageId,
            'toolCallId' => $toolCallId,
            'toolCall' => $toolText,
        ]);

        $this->chatStreamPublisher->publish($context->sessionId, 'message.assistant.placeholder', [
            'chatId' => $context->chatId,
            'sessionId' => $context->sessionId,
            'messageId' => $context->messageId,
            'messageArticleId' => $context->messageArticleId,
            'html' => $placeholderHtml,
        ]);
    }

    private function finalizeChat(JobContext $context): void
    {
        $finalHtml = $this->twig->fetch('partials/md.twig', [
            'message' => $context->getFinalContent(),
            'streaming_placeholder_images' => false,
        ]);

        $this->chatStreamPublisher->publish($context->sessionId, 'message.assistant.delta', [
            'chatId' => $context->chatId,
            'sessionId' => $context->sessionId,
            'messageId' => $context->messageId,
            'messageArticleId' => $context->messageArticleId,
            'html' => $finalHtml,
        ]);
        $this->chatStreamPublisher->publish($context->sessionId, 'message.assistant.done', [
            'chatId' => $context->chatId,
            'sessionId' => $context->sessionId,
            'messageId' => $context->messageId,
            'messageArticleId' => $context->messageArticleId,
        ]);
    }

    private function handleChatError(\Throwable $throwable, JobContext $context): void
    {
        $this->logger->error('Web chat job failed', [
            'exception' => $throwable,
            'chatId' => $context->chatId,
            'sessionId' => $context->sessionId,
        ]);
        $this->chatStreamPublisher->publish($context->sessionId, 'chat.error', [
            'chatId' => $context->chatId,
            'sessionId' => $context->sessionId,
            'message' => 'Désolé, une erreur est survenue lors du traitement de votre message.',
        ]);
    }

    private function formatToolChunk(ToolCallChunk|ToolResultChunk $chunk): string
    {
        $tool = $chunk->tool;
        $toolText = $this->formatToolHeader($chunk, $tool);
        $toolText .= $this->formatToolInputs($tool);
        $toolText .= $this->formatToolResult($chunk, $tool);

        return $toolText;
    }

    private function formatToolHeader(ToolCallChunk|ToolResultChunk $chunk, mixed $tool): string
    {
        $header = $chunk instanceof ToolResultChunk
            ? '<span class="tools-done-flag" style="display:none"></span>' . "\n"
            : '';
        $header .= "Utilisation de l'outil : " . $tool->getName() . "<br>\n";

        return $header;
    }

    private function formatToolInputs(mixed $tool): string
    {
        $inputs = "Paramètres : <br>\n<ul>\n";
        foreach ($tool->getInputs() as $name => $value) {
            $inputs .= '<li>' . $name . ' : ' . $value . "</li>\n";
        }
        $inputs .= "</ul>\n";

        return $inputs;
    }

    private function formatToolResult(ToolCallChunk|ToolResultChunk $chunk, mixed $tool): string
    {
        if (! $chunk instanceof ToolResultChunk) {
            return '';
        }

        $result = "Réponse : <br>\n";
        if ($tool->getResult() !== '' && $tool->getResult() !== '0') {
            $result .= '<pre class="toolcall__result">' . $tool->getResult() . "</pre>\n";
        }

        return $result;
    }

    private function addAttachments(UserMessage $userMessage, mixed $attachments): void
    {
        if (! is_array($attachments)) {
            return;
        }

        $this->addFileAttachments($userMessage, $attachments['uploadedFiles'] ?? []);
        $this->addFileAttachments($userMessage, $attachments['fileIds'] ?? []);
    }

    /**
     * @param array<mixed> $files
     */
    private function addFileAttachments(UserMessage $userMessage, array $files): void
    {
        foreach ($files as $file) {
            $this->addSingleAttachment($userMessage, $file);
        }
    }

    private function addSingleAttachment(UserMessage $userMessage, mixed $file): void
    {
        if (! is_array($file)) {
            return;
        }

        $content = (string) ($file['content'] ?? '');
        if ($content === '') {
            return;
        }

        $userMessage->addContent(new \NeuronAI\Chat\Messages\ContentBlocks\FileContent(
            $content,
            \NeuronAI\Chat\Enums\SourceType::BASE64,
            (string) ($file['mimeType'] ?? 'application/octet-stream'),
            (string) ($file['filename'] ?? 'file'),
        ));
    }

    private function manageSummary(SessionInterface $session): void
    {
        $summary = new Summary($this->connection, $this->settings, $session);
        $chatHistory = $summary->getChatHistory();

        if (! $this->shouldGenerateSummary($chatHistory)) {
            return;
        }

        $summary->generateAndPersist();
    }

    private function shouldGenerateSummary(?UserChatHistory $chatHistory): bool
    {
        return $chatHistory !== null
            && $this->hasEnoughMessages($chatHistory)
            && ! $this->hasMaxMessagesWithTitle($chatHistory);
    }

    private function hasEnoughMessages(UserChatHistory $chatHistory): bool
    {
        $messages = $chatHistory->getDisplayMessages();
        $minMessages = $this->settings->get('llm.summary.minMessages');

        return $messages !== [] && count($messages) >= $minMessages;
    }

    private function hasMaxMessagesWithTitle(UserChatHistory $chatHistory): bool
    {
        $messages = $chatHistory->getDisplayMessages();
        $maxMessages = $this->settings->get('llm.summary.maxMessages');

        return count($messages) > $maxMessages && $chatHistory->getTitle() !== null;
    }
}
