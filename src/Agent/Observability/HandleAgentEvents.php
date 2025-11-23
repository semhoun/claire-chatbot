<?php

declare(strict_types=1);

namespace App\Agent\Observability;

use NeuronAI\Agent\Agent;
use NeuronAI\RAG\RAG;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Tools\ToolPropertyInterface;
use OpenTelemetry\API\Trace\SpanInterface as Span;

/**
 * Handle Agent and RAG events.
 */
trait HandleAgentEvents
{
    /**
     * @var array<string, Span>
     */
    protected array $agentSpans = [];

    /**
     * @throws \Exception
     */
    public function start(Agent|RAG $source, string $event, mixed $data = null): void
    {
        $method = $this->getEventPrefix($event);
        $class = $source::class;

        $key = $class.$method;

        if (\array_key_exists($key, $this->agentSpans)) {
            $key .= '-'.\uniqid('', true);
        }

        $span = $this->instrumentation->tracer()->spanBuilder(self::SPAN_TYPE.'.'.$method)
            ->startSpan();
        $agentContext = $this->getAgentContext($source);
        $agentContext['Event'] = $event;
        $this->spanSetAttributes($span, 'neuron', $agentContext);

        $this->agentSpans[$key] = $span;
    }

    /**
     * @throws \Exception
     */
    public function stop(Agent|RAG $agent, string $event, mixed $data = null): void
    {
        $method = $this->getEventPrefix($event);
        $class = $agent::class;

        $key = $class.$method;

        if (\array_key_exists($key, $this->agentSpans)) {
            foreach (\array_reverse($this->agentSpans) as $key => $span) {
                if ($key === $class.$method) {
                    $span->end();
                    unset($this->agentSpans[$key]);
                    break;
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAgentContext(Agent $agent): array
    {
        $mapTool = static fn (ToolInterface $tool): array => [
            $tool->getName() => [
                'description' => $tool->getDescription(),
                'properties' => \array_map(
                    static fn (ToolPropertyInterface $toolProperty) => $toolProperty->jsonSerialize(),
                    $tool->getProperties()
                ),
            ],
        ];

        return [
            'Agent' => [
                'provider' => $agent->resolveProvider()::class,
                'instructions' => $agent->resolveInstructions(),
            ],
            'Tools' => \array_map(static fn (ToolInterface|ToolkitInterface|ProviderToolInterface $tool) => match (true) {
                $tool instanceof ToolInterface => $mapTool($tool),
                $tool instanceof ToolkitInterface => [$tool::class => \array_map($mapTool, $tool->tools())],
                default => $tool->jsonSerialize(),
            }, $agent->getTools()),
        ];
    }
}
