<?php

declare(strict_types=1);

namespace App\Job\Web;

use App\Brain\Agent;
use App\Brain\BrainRegistry;
use App\Renderer\ChatDataRenderer;
use App\Services\Auth;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\ChatThreadLock;
use App\Services\ChatTurnJournal;
use App\Services\Queue\NonRetryableJobException;
use App\Services\Queue\QueueDoer;
use App\Services\Session\InMemorySession;
use Doctrine\DBAL\Connection;
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

    private string $userId = '';

    public function __construct(
        private readonly ChatDataRenderer $chatDataRenderer,
        private readonly BrainRegistry $brainRegistry,
        private readonly ChatStreamPublisher $chatStreamPublisher,
        private readonly Connection $connection,
    ) {
    }

    public static function make(ContainerInterface $container): self
    {
        return $container->get(self::class);
    }

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        $this->agent = null;
        $this->threadId = '';
        $this->sessionId = '';
        $this->userId = '';
        $this->initContext($payload);
        $chatThreadLock = new ChatThreadLock($this->connection->getNativeConnection(), $this->userId, $this->threadId);
        $chatGenerationState = $this->chatStreamPublisher->generationState();
        $journal = new ChatTurnJournal($this->connection);
        $messageId = 'opening-' . $this->threadId;
        $attempted = false;
        try {
            $turn = $journal->get($messageId);
            if ($turn !== null) {
                if ($turn['userId'] !== $this->userId || $turn['threadId'] !== $this->threadId) {
                    throw new \RuntimeException('Opening turn identity conflict');
                }
                if ($turn['status'] !== 'running') {
                    return;
                }
                throw new NonRetryableJobException('Abandoned opening must not replay the agent');
            }

            // Redis loss must not let an old opening replace a used or deleted conversation.
            if ($this->connection->fetchOne(
                'SELECT id FROM chat_turn WHERE user_id = ? AND thread_id = ?',
                [$this->userId, $this->threadId],
            ) !== false || $this->connection->fetchOne(
                'SELECT thread_id FROM chat_history WHERE thread_id = ?'
                . " AND (user_id <> ? OR messages <> '[]' OR display_messages <> '[]'"
                . ' OR display_messages_count <> 0 OR current_turn_id IS NOT NULL)',
                [$this->threadId, $this->userId],
            ) !== false) {
                return;
            }
            $previous = $chatGenerationState->get($this->userId, $this->threadId);
            if (in_array($previous['status'] ?? '', ['done', 'deleted'], true)
                || ($previous['messageId'] ?? $messageId) !== $messageId) {
                return;
            }
            $attempted = ($previous['attempted'] ?? '0') === '1';
            if ($attempted) {
                throw new NonRetryableJobException('Unsafe opening generation retry refused');
            }

            $inMemorySession = new InMemorySession($payload['session']);
            if (! $this->brainRegistry->has($inMemorySession->get('brain_avatar'))) {
                throw new \InvalidArgumentException('Unknown opening assistant');
            }
            $chatThreadLock->assertHeld();
            $turn = $journal->begin($messageId, $this->userId, $this->threadId, 'web', $messageId,
                notification: ['sessionId' => $this->sessionId]);
            if (! $turn['entered']) {
                return;
            }
            $attempted = true;
            $chatGenerationState->set($this->userId, $this->threadId, $messageId, 'running', true);
            $chatThreadLock->assertHeld();
            $this->agent = $this->brainRegistry->get($inMemorySession->get('brain_avatar'), $inMemorySession, $this->threadId);
            $messages = $this->startNewStream($chatThreadLock);
            $chatThreadLock->assertHeld();
            $journal->succeed($messageId, $this->userId);
            $chatGenerationState->set($this->userId, $this->threadId, $messageId, 'done', true);
        } catch (\Throwable $throwable) {
            $chatThreadLock->assertHeld();
            $turn = $journal->get($messageId);
            if ($turn !== null) {
                if ($turn['userId'] !== $this->userId || $turn['threadId'] !== $this->threadId) {
                    throw $throwable;
                }
                // SQL success wins, including a lost commit acknowledgement or Redis failure.
                if ($turn['status'] === 'succeeded' || $turn['deletedAt'] !== null) {
                    return;
                }
                $turn = $journal->rollback($messageId, $this->userId);
                $attempted = true;
            }
            try {
                $previous = $chatGenerationState->get($this->userId, $this->threadId);
                if (($previous['messageId'] ?? $messageId) === $messageId
                    && ($previous['status'] ?? '') !== 'deleted') {
                    $chatGenerationState->set($this->userId, $this->threadId, $messageId, 'error', $attempted);
                    $this->handleChatError($throwable, ($turn['status'] ?? '') === 'rolled_back');
                }
            } finally {
                throw $attempted
                    ? new NonRetryableJobException('Opening attempt failed after agent entry', 0, $throwable)
                    : $throwable;
            }
        } finally {
            $this->agent = null;
            $chatThreadLock->release();
        }

        $this->chatStreamPublisher->publish($this->sessionId, 'chat.snapshot', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messages' => $messages,
            ...$chatGenerationState->snapshot($this->userId, $this->threadId),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function startNewStream(ChatThreadLock $chatThreadLock): array
    {
        $chatThreadLock->assertHeld();
        $openingMessage = $this->agent->getOpeningText();
        $chatThreadLock->assertHeld();
        $assistantMessage = new AssistantMessage($openingMessage)
            ->addMetadata('timestamp', new \DateTimeImmutable()->format(\DateTimeInterface::ATOM));
        $chatHistory = $this->agent->getChatHistory();

        // Replace the technical generation exchange with a valid hidden
        // context followed by the opening message actually shown to the user.
        $chatHistory->initializeWithOpeningMessage($assistantMessage);

        return $this->chatDataRenderer->messages(
            $chatHistory->getFormattedMessages(),
            $this->userId,
        );
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
        $this->userId = (string) $inMemorySession->get(Auth::USERID);
        $this->sessionId = ChatStreamSubscriber::scope($this->userId, $this->sessionId);
    }

    private function handleChatError(\Throwable $throwable, bool $rollbackConfirmed = false): void
    {
        if ($this->sessionId === '') {
            throw $throwable;
        }

        $this->chatStreamPublisher->publish($this->sessionId, 'chat.error', [
            'threadId' => $this->threadId,
            'sessionId' => $this->sessionId,
            'messageId' => 'opening-' . $this->threadId,
            'rollbackConfirmed' => $rollbackConfirmed,
            'turnStatus' => $rollbackConfirmed ? 'rolled_back' : null,
            'message' => 'Impossible de démarrer la conversation.',
        ]);
    }
}
