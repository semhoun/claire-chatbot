<?php

declare(strict_types=1);

namespace App\Job\Web;

use App\Brain\Agent;
use App\Brain\BrainRegistry;
use App\Renderer\ChatHtmlRenderer;
use App\Services\ChatStreamPublisher;
use App\Services\Queue\QueueDoer;
use App\Services\Session\InMemorySession;
use NeuronAI\Chat\Messages\AssistantMessage;
use Psr\Container\ContainerInterface;

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
    private string $threadId = '';

    private string $sessionId = '';

    private ?Agent $agent = null;

    public function __construct(
        private readonly ChatHtmlRenderer $chatHtmlRenderer,
        private readonly BrainRegistry $brainRegistry,
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

    public function startNewStream(): void
    {
        $openingMessage = $this->agent->getOpeningText();
        $assistantMessage = new AssistantMessage($openingMessage)
            ->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));
        $chatHistory = $this->agent->getChatHistory();

        // Replace the technical generation exchange with a valid hidden
        // context followed by the opening message actually shown to the user.
        $chatHistory->initializeWithOpeningMessage($assistantMessage);

        $messagesHtml = $this->chatHtmlRenderer->messages(
            $chatHistory->getFormattedMessages()
        );
        $this->chatStreamPublisher->publish($this->sessionId, 'chat.snapshot', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'html' => $messagesHtml,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function initContext(array $payload): void
    {
        $this->threadId = (string) ($payload['threadId'] ?? '');
        if ($this->threadId === '') {
            throw new \InvalidArgumentException('Thread ID cannot be empty');
        }

        $this->sessionId = (string) ($payload['sessionId'] ?? '');
        if ($this->sessionId === '') {
            throw new \InvalidArgumentException('Session ID cannot be empty');
        }

        $inMemorySession = new InMemorySession($payload['session']);
        $brainAvatar = $inMemorySession->get('brain_avatar');
        $this->agent = $this->brainRegistry->get($brainAvatar, $inMemorySession, $this->threadId);
    }

    private function handleChatError(\Throwable $throwable): void
    {
        if ($this->sessionId === '') {
            throw $throwable;
        }

        $this->chatStreamPublisher->publish($this->sessionId, 'chat.error', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'message' => 'Impossible de démarrer la conversation.',
        ]);
    }
}
