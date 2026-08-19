<?php

declare(strict_types=1);

namespace App\Brain\Observability;

use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\ObserverInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\SpanInterface as Span;
use OpenTelemetry\API\Trace\StatusCode;

class Observer implements ObserverInterface
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
        'error' => 'reportError',
        'chat-start' => 'start',
        'chat-stop' => 'stop',
        'stream-start' => 'start',
        'stream-stop' => 'stop',
        'structured-start' => 'start',
        'structured-stop' => 'stop',
        'chat-rag-start' => 'start',
        'chat-rag-stop' => 'stop',
        'stream-rag-start' => 'start',
        'stream-rag-stop' => 'stop',
        'structured-rag-start' => 'start',
        'structured-rag-stop' => 'stop',

        'message-saving' => 'messageSaving',
        'message-saved' => 'messageSaved',
        'tools-bootstrapping' => 'toolsBootstrapping',
        'tools-bootstrapped' => 'toolsBootstrapped',
        'inference-start' => 'inferenceStart',
        'inference-stop' => 'inferenceStop',
        'tool-calling' => 'toolCalling',
        'tool-called' => 'toolCalled',
        'schema-generation' => 'schemaGeneration',
        'schema-generated' => 'schemaGenerated',
        'structured-extracting' => 'extracting',
        'structured-extracted' => 'extracted',
        'structured-deserializing' => 'deserializing',
        'structured-deserialized' => 'deserialized',
        'structured-validating' => 'validating',
        'structured-validated' => 'validated',
        'rag-retrieving' => 'ragRetrieving',
        'rag-retrieved' => 'ragRetrieved',
        'rag-preprocessing' => 'preProcessing',
        'rag-preprocessed' => 'preProcessed',
        'rag-postprocessing' => 'postProcessing',
        'rag-postprocessed' => 'postProcessed',

        'workflow-start' => 'workflowStart',
        'workflow-resume' => 'workflowStart',
        'workflow-end' => 'workflowEnd',
        'workflow-node-start' => 'workflowNodeStart',
        'workflow-node-end' => 'workflowNodeEnd',
    ];

    public function __construct(?object $instrumentation = null)
    {
        $this->instrumentation = $instrumentation
            ?? new \OpenTelemetry\API\Instrumentation\CachedInstrumentation(self::SPAN_TYPE);
    }

    public function onEvent(string $event, object $source, mixed $data = null, ?string $branchId = null): void
    {
        if (\array_key_exists($event, $this->methodsMap)) {
            $method = $this->methodsMap[$event];
            $this->$method($source, $event, $data);
        }
    }

    /**
     * @throws \Exception
     */
    public function reportError(object $source, string $event, AgentError $agentError): void
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
                $this->toolBootstrap ?? null,
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
        $messageJson = $message->jsonSerialize();
        if (isset($messageJson['content'])) {
            $messageJson['content'] = \array_map(static function (array $block): array {
                if (isset($block['source_type']) && $block['source_type'] === SourceType::BASE64->value) {
                    unset($block['source']);
                }

                return $block;
            }, $messageJson['content']);
        }

        return $messageJson;
    }

    protected function spanSetAttributes(Span $span, string $attribute, mixed $data): void
    {
        if (\is_string($data)) {
            $span->setAttribute($attribute, $data);
            return;
        }

        foreach ($data as $key => $value) {
            if (\is_string($value)) {
                $span->setAttribute($attribute . '.' . $key, $value);
            } else {
                $span->setAttribute($attribute . '.' . $key, \json_encode($value));
            }
        }
    }
}
