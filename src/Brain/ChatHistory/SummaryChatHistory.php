<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

class SummaryChatHistory extends UserChatHistory
{
    #[\Override]
    /** @param array<Message> $messages */
    protected function setMessages(array $messages): void
    {
    }

    #[\Override]
    protected function clear(): void
    {
    }
}
