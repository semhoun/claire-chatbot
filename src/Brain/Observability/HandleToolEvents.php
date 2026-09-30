<?php

declare(strict_types=1);

namespace App\Brain\Observability;

use NeuronAI\Agent\Observability\ToolCalled;
use NeuronAI\Agent\Observability\ToolCalling;
use OpenTelemetry\API\Trace\SpanInterface as Span;

trait HandleToolEvents
{
    /** @var array<string, Span> */
    protected array $toolCalls = [];

    public function toolCalling(object $source, string $event, ToolCalling $toolCalling): void
    {
        $id = $toolCalling->tool->getCallId();
        $this->toolCalls[$id] = $this->instrumentation->tracer()
            ->spanBuilder(self::SPAN_TYPE . '.tool.tool_call(' . $toolCalling->tool->getName() . ')')
            ->startSpan();
        $this->toolCalls[$id]->setAttribute('neuron.tool.call_id', $id);
    }

    public function toolCalled(object $source, string $event, ToolCalled $toolCalled): void
    {
        $id = $toolCalled->tool->getCallId();
        if (! isset($this->toolCalls[$id])) {
            return;
        }
        $span = $this->toolCalls[$id];
        $this->spanSetAttributes($span, 'neuron', [
            'Inputs' => $toolCalled->tool->getInputs(),
            'Output' => $toolCalled->tool->hasResult() ? $toolCalled->tool->getResult() : null,
        ]);
        $span->end();
        unset($this->toolCalls[$id]);
    }
}
