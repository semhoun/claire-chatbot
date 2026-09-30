<?php

declare(strict_types=1);

namespace App\Brain\Middleware;

use App\Services\GenerationStopToken;
use NeuronAI\Agent\Observability\ToolCalling;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowResources;
use NeuronAI\Workflow\WorkflowState;

final readonly class CooperativeStop implements WorkflowMiddleware
{
    public function __construct(private GenerationStopToken $generationStopToken)
    {
    }

    /** Must also be subscribed: middleware alone only gates a whole tool batch. */
    public function __invoke(ToolCalling $toolCalling): void
    {
        $this->generationStopToken->check();
    }

    public function before(
        NodeInterface $node,
        Event $event,
        WorkflowState $state,
        WorkflowResources $resources,
    ): void {
        $this->generationStopToken->check();
    }

    public function after(
        NodeInterface $node,
        Event $result,
        WorkflowState $state,
        WorkflowResources $resources,
    ): void {
    }
}
