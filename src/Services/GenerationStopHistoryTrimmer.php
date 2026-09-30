<?php

declare(strict_types=1);

namespace App\Services;

use NeuronAI\Chat\History\HistoryTrimmer;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;

/** Preserve stopped turn boundaries without inventing an assistant response. */
final class GenerationStopHistoryTrimmer extends HistoryTrimmer
{
    protected function validateAlternation(array $messages): void
    {
        $segment = [];
        $previous = null;
        foreach ($messages as $message) {
            if ($message instanceof UserMessage
                && ! $message instanceof ToolResultMessage
                && $previous !== null
                && ! $previous instanceof ToolCallMessage
                && $previous->getMetadata('generation_stopped') === true) {
                parent::validateAlternation($segment);
                $segment = [];
            }
            $segment[] = $message;
            $previous = $message;
        }
        parent::validateAlternation($segment);
    }
}
