<?php

declare(strict_types=1);

namespace App\Brain\ChatHistory;

use App\Services\GenerationStopHistoryTrimmer;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\Message;

/** The segment's mutable context, with explicit non-destructive compaction. */
final class WorkingChatHistory extends ChatHistory
{
    public function __construct(
        private readonly UserChatHistory $userChatHistory,
        string $threadId,
        int $contextWindow = self::DEFAULT_CONTEXT_WINDOW,
    ) {
        parent::__construct($userChatHistory->messageStore(), $threadId, $contextWindow, new GenerationStopHistoryTrimmer());
    }

    /** @param list<Message> $messages */
    public function replaceActiveMessages(array $messages): void
    {
        $this->userChatHistory->replaceMessages($messages);
        $this->messages = $messages;
    }
}
