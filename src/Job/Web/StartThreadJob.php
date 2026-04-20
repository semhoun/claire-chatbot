<?php

declare(strict_types=1);

namespace App\Job\Web;

use App\Brain\Agent;
use App\Brain\BrainRegistry;
use App\Brain\ChatHistory\UserChatHistory;
use App\Brain\Summary;
use App\Services\ChatStreamPublisher;
use App\Services\Queue\QueueDoer;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use NeuronAI\Chat\Messages\AssistantMessage;
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
final class StartThreadJob implements QueueDoer
{
    private string $chatId;
    private string $sessionId;
    private ?Agent $agent;

    public function __construct(
        private readonly Logger              $logger,
        private readonly Twig                $twig,
        private readonly BrainRegistry       $brainRegistry,
        private readonly ChatStreamPublisher $chatStreamPublisher,
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
            $this->startNewStream();
        } catch (\Throwable $throwable) {
            $this->handleChatError($throwable);
        }
    }

    private function initContext(array $payload): void
    {
        $this->chatId = (string) ($payload['chatId'] ?? '');
        if ($this->chatId === '') {
            throw new \InvalidArgumentException('Chat ID cannot be empty');
        }
        $this->sessionId = (string) ($payload['sessionId'] ?? '');
        if ($this->sessionId === '') {
            throw new \InvalidArgumentException('Session ID cannot be empty');
        }

        $session = new InMemorySession($payload['session']);
        $brainAvatar = $session->get('brain_avatar');
        $this->agent = $this->brainRegistry->get($brainAvatar,  $session, $this->chatId);
    }

    public function startNewStream(): void
    {
        $openingMessage = $this->agent->getOpeningText();
        $assistantMessage = new AssistantMessage($openingMessage)
            ->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));
        $chatHistory = $this->agent->getChatHistory();

        $chatHistory->replaceMessages([]);
        $chatHistory->replaceDisplayMessages([$assistantMessage]);

        $messagesHtml = $this->twig->fetch('partials/messages_list.twig', [
            'messages' => $chatHistory->getFormattedMessages(),
        ]);
        $this->chatStreamPublisher->publish($this->sessionId, 'chat.snapshot', [
            'chatId' => $this->chatId,
            'sessionId' => $this->sessionId,
            'html' => $messagesHtml,
        ]);
    }
}
