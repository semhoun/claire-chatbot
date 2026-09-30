<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\Message;

/** One request/job and one owner. Construction performs no I/O. */
final readonly class UserMessageStore implements MessageStoreInterface
{
    private ?string $threadId;

    public function __construct(private UserChatHistory $userChatHistory)
    {
        $this->threadId = $userChatHistory->getThreadId();
    }

    /** @return list<Message> */
    public function loadActive(string $threadId): array
    {
        $this->guard($threadId);
        return $this->userChatHistory->getMessages();
    }

    /** @return list<Message> */
    public function loadAll(string $threadId, ?int $limit = null, ?string $before = null): array
    {
        $this->guard($threadId);
        return $this->userChatHistory->getStoredMessages($limit, $before);
    }

    public function append(string $threadId, Message $message): void
    {
        $this->guard($threadId);
        $this->userChatHistory->addMessage($message);
    }

    public function archive(string $threadId, int $count): void
    {
        $this->guard($threadId);
        $this->userChatHistory->archiveMessages($count);
    }

    public function clear(string $threadId): void
    {
        $this->guard($threadId);
        $this->userChatHistory->flushAll();
    }

    private function guard(string $threadId): void
    {
        $this->userChatHistory->assertOwner();
        if ($threadId !== $this->threadId || $threadId !== $this->userChatHistory->getThreadId()) {
            throw new \RuntimeException('Chat message store thread mismatch');
        }

        $this->userChatHistory->refresh();
    }
}
