<?php

declare(strict_types=1);

namespace App\Brain\Observability;

use NeuronAI\Workflow\Observability\WorkflowEnd;
use NeuronAI\Workflow\Observability\WorkflowNodeEnd;
use NeuronAI\Workflow\Observability\WorkflowNodeStart;
use NeuronAI\Workflow\Observability\WorkflowStart;

trait HandleWorkflowEvents
{
    /**
     * @throws \Exception
     */
    public function workflowStart(object $workflow, string $event, WorkflowStart $workflowStart): void
    {
        $this->spans[$workflow::class] = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.workflow.'. $this->getBaseClassName($workflow::class))
            ->startSpan();
        if ($workflow instanceof \NeuronAI\Agent\Agent) {
            $this->spanSetAttributes($this->spans[$workflow::class], 'neuron', $this->getAgentContext($workflow));
        }
    }

    public function workflowEnd(object $workflow, string $event, WorkflowEnd $workflowEnd): void
    {
        try {
            if (isset($this->spans[$workflow::class])) {
                $this->spanSetAttributes($this->spans[$workflow::class], 'neuron.State', $workflowEnd->state->all());
                $this->spans[$workflow::class]->setAttribute('neuron.status', $workflowEnd->state->getStatus()->value);
            }
        } finally {
            // Failed inference/tools and suspensions may have no matching domain stop event.
            foreach ($this->getActiveSpans() as $span) {
                $span->end();
            }
            $this->spans = $this->agentSpans = $this->toolCalls = [];
            unset($this->message, $this->inference, $this->schema, $this->extract, $this->deserialize, $this->validate);
        }
    }

    public function workflowNodeStart(object $workflow, string $event, WorkflowNodeStart $workflowNodeStart): void
    {
        $span = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.workflow.'. $this->getBaseClassName($workflowNodeStart->node))
            ->startSpan();
        $this->spanSetAttributes($span, 'neuron.Before', $workflowNodeStart->state->all());
        if ($workflowNodeStart->state instanceof \NeuronAI\Agent\AgentState
            && isset($workflowNodeStart->state->request)) {
            $span->setAttribute('neuron.instructions', $workflowNodeStart->state->request->instructions->getContent() ?? '');
        }
        $this->spans[$workflowNodeStart->node] = $span;
    }

    public function workflowNodeEnd(object $workflow, string $event, WorkflowNodeEnd $workflowNodeEnd): void
    {
        if (! \array_key_exists($workflowNodeEnd->node, $this->spans)) {
            return;
        }

        $span = $this->spans[$workflowNodeEnd->node];
        $this->spanSetAttributes($span, 'neuron.After', $workflowNodeEnd->state->all());
        $span->end();
        unset($this->spans[$workflowNodeEnd->node]);
    }
}
