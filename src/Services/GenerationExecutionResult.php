<?php

declare(strict_types=1);

namespace App\Services;

use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Messages\Message;

final readonly class GenerationExecutionResult
{
    /**
     * @param list<Message> $messages Current turn with raw assistant snapshots,
     *     not final postprocessed text. Use these if terminal arbitration stops
     *     an otherwise completed execution; state retains normal postprocessing.
     */
    public function __construct(
        public bool $stopped,
        public ?AgentState $state,
        public array $messages,
    ) {
    }
}
