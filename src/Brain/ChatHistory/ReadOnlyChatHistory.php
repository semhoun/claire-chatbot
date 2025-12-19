<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

class ReadOnlyChatHistory extends UserChatHistory
{
    #[\Override]
    protected function setMessages(array $messages): void
    {
    }

    #[\Override]
    protected function clear(): void
    {
    }
}
