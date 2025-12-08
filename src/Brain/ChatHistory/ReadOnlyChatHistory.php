<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use NeuronAI\Chat\History\ChatHistoryInterface;

class ReadOnlyChatHistory extends UserChatHistory
{
    #[\Override]
    public function setMessages(array $messages): ChatHistoryInterface
    {
        return $this;
    }

    #[\Override]
    protected function clear(): ChatHistoryInterface
    {
        return $this;
    }
}
