<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\Message;

/** One request/job and one owner. Construction performs no I/O. */
final readonly class UserMessageStore implements MessageStoreInterface
{
    private ?string $threadId;

    public function __construct(private UserChatHistory $history)
    {
        $this->threadId = $history->getThreadId();
    }

    public function loadActive(string $threadId): array
    {
        $this->guard($threadId);
        return $this->history->getMessages();
    }

    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        $this->guard($threadId);
        return $this->history->getStoredMessages($limit, $before);
    }

    public function append(string $threadId, Message $message): void
    {
        $this->guard($threadId);
        $this->history->addMessage($message);
    }

    public function archive(string $threadId, int $count): void
    {
        $this->guard($threadId);
        $this->history->archiveMessages($count);
    }

    public function clear(string $threadId): void
    {
        $this->guard($threadId);
        $this->history->flushAll();
    }

    private function guard(string $threadId): void
    {
        $this->history->assertOwner();
        if ($threadId !== $this->threadId || $threadId !== $this->history->getThreadId()) {
            throw new \RuntimeException('Chat message store thread mismatch');
        }
        $this->history->refresh();
    }
}
