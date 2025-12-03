<?php

declare(strict_types=1);

namespace App\Agent\Observability;

use NeuronAI\Agent\AgentInterface;
use NeuronAI\Observability\Events\ToolCalled;
use NeuronAI\Observability\Events\ToolCalling;
use NeuronAI\Observability\Events\ToolsBootstrapped;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use OpenTelemetry\API\Trace\SpanInterface as Span;

trait HandleToolEvents
{
    protected Span $toolBootstrap;

    /**
     * @var array<Span>
     */
    protected array $toolCalls = [];

    public function toolsBootstrapping(AgentInterface $agent, string $event, mixed $data): void
    {
        if (! $agent->getTools() === []) {
            return;
        }

        $this->toolBootstrap = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.tool.tools_bootstrap()')
            ->startSpan();
    }

    public function toolsBootstrapped(object $source, string $event, ToolsBootstrapped $toolsBootstrapped): void
    {
        if (! isset($this->toolBootstrap)) {
            return;
        }

        $this->spanSetAttributes($this->toolBootstrap, 'neuron.Tools', \array_reduce($toolsBootstrapped->tools, static function (array $carry, ToolInterface|ProviderToolInterface $tool): array {
            if ($tool instanceof ProviderToolInterface) {
                $carry[$tool->getType()] = $tool->getOptions();
            } else {
                $carry[$tool->getName()] = $tool->getDescription();
            }

            return $carry;
        }, []));
        $this->spanSetAttributes($this->toolBootstrap, 'neuron.Guidelines', $toolsBootstrapped->guidelines);
        $this->toolBootstrap->end();
    }

    public function toolCalling(object $source, string $event, ToolCalling $toolCalling): void
    {
        $this->toolCalls[$toolCalling->tool::class] = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE . '.tool.tool_call('. $toolCalling->tool->getName() .')')
            ->startSpan();
    }

    public function toolCalled(object $source, string $event, ToolCalled $toolCalled): void
    {
        if (! \array_key_exists($toolCalled->tool::class, $this->toolCalls)) {
            return;
        }

        $this->spanSetAttributes($this->toolBootstrap, 'neuron', [
            'Properties' => $toolCalled->tool->getProperties(),
            'Inputs' => $toolCalled->tool->getInputs(),
            'Output' => $toolCalled->tool->getResult(),
        ]);
        $this->toolCalls[$toolCalled->tool::class]->end();
    }
}
