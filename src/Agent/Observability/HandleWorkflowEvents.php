<?php

declare(strict_types=1);

namespace App\Agent\Observability;

use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Observability\Events\WorkflowStart;

trait HandleWorkflowEvents
{
    /**
     * @throws \Exception
     */
    public function workflowStart(object $workflow, string $event, WorkflowStart $workflowStart): void
    {
        $this->spans[$workflow::class] = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.workflow.'. $this->getBaseClassName($workflow::class))
            ->startSpan();
    }

    public function workflowEnd(object $workflow, string $event, WorkflowEnd $workflowEnd): void
    {
        if (! \array_key_exists($workflow::class, $this->spans)) {
            return;
        }

        $span = $this->spans[$workflow::class];
        $this->spanSetAttributes($this->message, 'neuron.State', $workflowEnd->state->all());
        $span->end();
    }

    public function workflowNodeStart(object $workflow, string $event, WorkflowNodeStart $workflowNodeStart): void
    {
        $span = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.workflow.'. $this->getBaseClassName($workflowNodeStart->node))
            ->startSpan();
        $this->spanSetAttributes($span, 'neuron.Before', $workflowNodeStart->state->all());
        $this->spans[$workflowNodeStart->node] = $span;
    }

    public function workflowNodeEnd(object $workflow, string $event, WorkflowNodeEnd $workflowNodeEnd): void
    {
        if (! \array_key_exists($workflowNodeEnd->node, $this->spans)) {
            return;
        }

        $span = $this->spans[$workflowNodeEnd->node];
        $this->spanSetAttributes($this->message, 'neuron.After', $workflowNodeEnd->state->all());
        $span->end();
    }
}
