<?php

declare(strict_types=1);

namespace App\Brain\Observability;

use NeuronAI\Agent\Observability as AgentEvents;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\RAG\Observability as RagEvents;
use NeuronAI\Workflow\Observability as WorkflowEvents;
use NeuronAI\Workflow\Observability\WorkflowError;
use NeuronAI\Workflow\Workflow;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\SpanInterface as Span;
use OpenTelemetry\API\Trace\StatusCode;

class Observer
{
    use HandleAgentEvents;

    use HandleToolEvents;

    use HandleRagEvents;

    use HandleInferenceEvents;

    use HandleStructuredEvents;

    use HandleWorkflowEvents;

    public const SPAN_TYPE = 'neuron.ai';

    /**
     * @var array<string, Span>
     */
    protected array $spans = [];

    protected object $instrumentation;

    /**
     * @var array<string, string>
     */
    protected array $methodsMap = [
        WorkflowError::class => 'reportError',
        AgentEvents\MessageSaving::class => 'messageSaving',
        AgentEvents\MessageSaved::class => 'messageSaved',
        AgentEvents\InferenceStart::class => 'inferenceStart',
        AgentEvents\InferenceStop::class => 'inferenceStop',
        AgentEvents\ToolCalling::class => 'toolCalling',
        AgentEvents\ToolCalled::class => 'toolCalled',
        AgentEvents\SchemaGeneration::class => 'schemaGeneration',
        AgentEvents\SchemaGenerated::class => 'schemaGenerated',
        AgentEvents\Extracting::class => 'extracting',
        AgentEvents\Extracted::class => 'extracted',
        AgentEvents\Deserializing::class => 'deserializing',
        AgentEvents\Deserialized::class => 'deserialized',
        AgentEvents\Validating::class => 'validating',
        AgentEvents\Validated::class => 'validated',
        RagEvents\Retrieving::class => 'ragRetrieving',
        RagEvents\Retrieved::class => 'ragRetrieved',
        RagEvents\PreProcessing::class => 'preProcessing',
        RagEvents\PreProcessed::class => 'preProcessed',
        RagEvents\PostProcessing::class => 'postProcessing',
        RagEvents\PostProcessed::class => 'postProcessed',
        WorkflowEvents\WorkflowStart::class => 'workflowStart',
        WorkflowEvents\WorkflowEnd::class => 'workflowEnd',
        WorkflowEvents\WorkflowNodeStart::class => 'workflowNodeStart',
        WorkflowEvents\WorkflowNodeEnd::class => 'workflowNodeEnd',
    ];

    /** @var \WeakMap<Workflow, bool> */
    private \WeakMap $subscriptions;

    public function __construct(?object $instrumentation = null)
    {
        $this->subscriptions = new \WeakMap();
        $this->instrumentation = $instrumentation
            ?? new \OpenTelemetry\API\Instrumentation\CachedInstrumentation(self::SPAN_TYPE);
    }

    public function subscribeTo(Workflow $workflow): void
    {
        if (isset($this->subscriptions[$workflow])) {
            return;
        }
        foreach ($this->methodsMap as $eventClass => $method) {
            $workflow->subscribe($eventClass, function (ObservabilityEvent $event) use ($method): void {
                $this->$method($event->source ?? $event, $event->name(), $event);
            });
        }
        $this->subscriptions[$workflow] = true;
    }

    /**
     * @throws \Exception
     */
    public function reportError(object $source, string $event, WorkflowError $agentError): void
    {
        $logRecord = new LogRecord($agentError->exception->getMessage(), $agentError->exception->getTrace());
        $this->instrumentation->logger()->emit($logRecord);

        $attributes = [
            'error.message' => $agentError->exception->getMessage(),
            'error.type' => $agentError->exception::class,
            'error.stacktrace' => $agentError->exception->getTraceAsString(),
        ];

        foreach ($this->getActiveSpans() as $span) {
            $span->setStatus(StatusCode::STATUS_ERROR, $agentError->exception->getMessage());
            $span->recordException($agentError->exception, $attributes);
        }
    }

    public function getEventPrefix(string $event): string
    {
        return \explode('-', $event)[0];
    }

    /** @return array<Span> */
    protected function getActiveSpans(): array
    {
        return \array_filter([
            ...\array_values($this->agentSpans ?? []),
            ...\array_values($this->toolCalls ?? []),
            ...[
                $this->inference ?? null,
                $this->message ?? null,
                $this->schema ?? null,
                $this->extract ?? null,
                $this->deserialize ?? null,
                $this->validate ?? null,
            ],
            ...\array_values($this->spans ?? []),
        ], static fn (?Span $span): bool => $span instanceof Span);
    }

    protected function getBaseClassName(string $class): string
    {
        $pos = \strrpos($class, '\\');
        return $pos !== false ? \substr($class, $pos + 1) : $class;
    }

    /** @return array<string, mixed> */
    protected function prepareMessageItem(Message $message): array
    {
        return $this->redact($message->jsonSerialize());
    }

    protected function spanSetAttributes(Span $span, string $attribute, mixed $data): void
    {
        $data = $this->redact($data);
        if (\is_string($data)) {
            $span->setAttribute($attribute, $data);
            return;
        }

        foreach (is_array($data) ? $data : ['value' => $data] as $key => $value) {
            if (\is_string($value)) {
                $span->setAttribute($attribute . '.' . $key, $value);
            } else {
                $span->setAttribute($attribute . '.' . $key, \json_encode($value, JSON_THROW_ON_ERROR));
            }
        }
    }

    private function redact(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        if (! is_array($value)) {
            return $value;
        }
        if (($value['source_type'] ?? null) === SourceType::BASE64->value) {
            unset($value['source'], $value['content']);
        }
        foreach ($value as $key => $item) {
            $value[$key] = is_string($key)
                && preg_match('/(?:secret|password|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token)/i', $key)
                ? '[redacted]' : $this->redact($item);
        }
        return $value;
    }
}
