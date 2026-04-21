<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;

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
