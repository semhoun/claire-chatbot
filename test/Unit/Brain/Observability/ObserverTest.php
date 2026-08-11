<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain\Observability;

use App\Brain\Observability\Observer;
use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Observability\Events\AgentError;
use NeuronAI\Observability\Events\InferenceStart;
use NeuronAI\Observability\Events\InferenceStop;
use NeuronAI\Observability\Events\MessageSaved;
use NeuronAI\Observability\Events\MessageSaving;
use NeuronAI\Observability\Events\WorkflowEnd;
use NeuronAI\Observability\Events\WorkflowNodeEnd;
use NeuronAI\Observability\Events\WorkflowNodeStart;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Workflow\WorkflowState;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ObserverTest extends TestCase
{
    private Observer $observer;
    private TracerInterface $tracer;
    private LoggerInterface $logger;
    private SpanBuilderInterface $spanBuilder;
    private SpanInterface $span;

    protected function setUp(): void
    {
        $this->tracer = $this->createMock(TracerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->spanBuilder = $this->createMock(SpanBuilderInterface::class);
        $this->span = $this->createMock(SpanInterface::class);

        $this->spanBuilder->method('startSpan')->willReturn($this->span);
        $this->tracer->method('spanBuilder')->willReturn($this->spanBuilder);

        $instrumentation = new class($this->tracer, $this->logger) {
            public function __construct(
                private readonly object $tracer,
                private readonly object $logger,
            ) {
            }

            public function tracer(): object
            {
                return $this->tracer;
            }

            public function logger(): object
            {
                return $this->logger;
            }
        };

        $this->observer = new Observer($instrumentation);
    }

    // ── onEvent dispatch ────────────────────────────────────────────

    public function testOnEventIgnoresUnknownEvent(): void
    {
        $this->expectNotToPerformAssertions();
        $this->observer->onEvent('unknown-event', new \stdClass());
    }

    // ── reportError ─────────────────────────────────────────────────

    public function testReportErrorEmitsLogRecord(): void
    {
        $exception = new RuntimeException('test error message');

        $this->logger
            ->expects($this->once())
            ->method('emit')
            ->with($this->isInstanceOf(\OpenTelemetry\API\Logs\LogRecord::class));

        $this->observer->reportError(new \stdClass(), 'error', new AgentError($exception));
    }

    public function testReportErrorLogRecordContainsExceptionMessage(): void
    {
        $exception = new RuntimeException('body check');

        $this->logger
            ->expects($this->once())
            ->method('emit')
            ->with($this->callback(function ($record): bool {
                $ref = new \ReflectionProperty(\OpenTelemetry\API\Logs\LogRecord::class, 'body');
                return $ref->getValue($record) === 'body check';
            }));

        $this->observer->reportError(new \stdClass(), 'error', new AgentError($exception));
    }

    public function testReportErrorMarksActiveSpansAsErrored(): void
    {
        $exception = new RuntimeException('span error');

        $this->span
            ->expects($this->once())
            ->method('setStatus')
            ->with(StatusCode::STATUS_ERROR, 'span error')
            ->willReturn($this->span);

        $this->span
            ->expects($this->once())
            ->method('recordException')
            ->with(
                $exception,
                $this->callback(static fn (array $attrs): bool =>
                    $attrs['error.message'] === 'span error'
                    && $attrs['error.type'] === RuntimeException::class),
            )
            ->willReturn($this->span);

        $ref = new \ReflectionProperty(Observer::class, 'agentSpans');
        $ref->setValue($this->observer, ['Test\Foo' => $this->span]);

        $this->logger->method('emit');

        $this->observer->reportError(new \stdClass(), 'error', new AgentError($exception));
    }

    public function testReportErrorHandlesMultipleActiveSpans(): void
    {
        $exception = new RuntimeException('multi span error');

        $span1 = $this->createMock(SpanInterface::class);
        $span2 = $this->createMock(SpanInterface::class);

        foreach ([$span1, $span2] as $span) {
            $span->expects($this->once())->method('setStatus')
                ->with(StatusCode::STATUS_ERROR)->willReturn($span);
            $span->expects($this->once())->method('recordException')
                ->willReturn($span);
        }

        $ref = new \ReflectionProperty(Observer::class, 'spans');
        $ref->setValue($this->observer, ['key1' => $span1, 'key2' => $span2]);

        $this->logger->method('emit');

        $this->observer->reportError(new \stdClass(), 'error', new AgentError($exception));
    }

    public function testReportErrorWithNoActiveSpansDoesNotThrow(): void
    {
        $exception = new RuntimeException('no spans');

        $this->logger->expects($this->once())->method('emit');

        $this->observer->reportError(new \stdClass(), 'error', new AgentError($exception));
    }

    // ── getEventPrefix ──────────────────────────────────────────────

    public function testGetEventPrefix(): void
    {
        $this->assertSame('chat', $this->observer->getEventPrefix('chat-start'));
        $this->assertSame('chat', $this->observer->getEventPrefix('chat-stop'));
        $this->assertSame('stream', $this->observer->getEventPrefix('stream-start'));
        $this->assertSame('workflow', $this->observer->getEventPrefix('workflow-node-start'));
        $this->assertSame('inference', $this->observer->getEventPrefix('inference-stop'));
    }

    // ── getBaseClassName ────────────────────────────────────────────

    public function testGetBaseClassName(): void
    {
        $result = $this->invokeProtectedMethod($this->observer, 'getBaseClassName', ['App\Brain\SomeBrain']);
        $this->assertSame('SomeBrain', $result);
    }

    public function testGetBaseClassNameWithoutNamespace(): void
    {
        $result = $this->invokeProtectedMethod($this->observer, 'getBaseClassName', ['PlainClass']);
        $this->assertSame('PlainClass', $result);
    }

    // ── prepareMessageItem ──────────────────────────────────────────

    public function testPrepareMessageItemStripsBase64Content(): void
    {
        $message = new UserMessage('Hello');
        $message->addContent(new class('secret') extends ContentBlock {
            public function getType(): ContentBlockType { return ContentBlockType::IMAGE; }
            public function toArray(): array {
                return ['source_type' => SourceType::BASE64->value, 'source' => $this->content];
            }
        });

        $result = $this->invokeProtectedMethod($this->observer, 'prepareMessageItem', [$message]);

        foreach ($result['content'] as $block) {
            if (isset($block['source_type']) && $block['source_type'] === SourceType::BASE64->value) {
                $this->assertArrayNotHasKey('source', $block);
            }
        }
    }

    public function testPrepareMessageItemPreservesNonBase64Content(): void
    {
        $message = new AssistantMessage('Hi');
        $message->addContent(new class('visible text') extends ContentBlock {
            public function getType(): ContentBlockType { return ContentBlockType::TEXT; }
            public function toArray(): array {
                return ['source_type' => SourceType::URL->value, 'source' => $this->content];
            }
        });

        $result = $this->invokeProtectedMethod($this->observer, 'prepareMessageItem', [$message]);

        $urlBlocks = \array_filter($result['content'], static fn (array $b): bool =>
            isset($b['source_type']) && $b['source_type'] === SourceType::URL->value);
        $this->assertNotEmpty($urlBlocks);
    }

    // ── spanSetAttributes ───────────────────────────────────────────

    public function testSpanSetAttributesWithString(): void
    {
        $this->span
            ->expects($this->once())
            ->method('setAttribute')
            ->with('neuron.key', 'value');

        $this->invokeProtectedMethod(
            $this->observer,
            'spanSetAttributes',
            [$this->span, 'neuron.key', 'value'],
        );
    }

    public function testSpanSetAttributesWithArray(): void
    {
        $this->span
            ->expects($this->exactly(2))
            ->method('setAttribute')
            ->willReturnCallback(function (string $key, $value): SpanInterface {
                $expected = [
                    'neuron.a' => 'x',
                    'neuron.b' => 'y',
                ];
                $this->assertArrayHasKey($key, $expected);
                $this->assertSame($expected[$key], $value);
                return $this->span;
            });

        $this->invokeProtectedMethod(
            $this->observer,
            'spanSetAttributes',
            [$this->span, 'neuron', ['a' => 'x', 'b' => 'y']],
        );
    }

    public function testSpanSetAttributesWithNestedArrayJsonEncodesNonStrings(): void
    {
        $this->span
            ->expects($this->once())
            ->method('setAttribute')
            ->with('neuron.nested', '42')
            ->willReturn($this->span);

        $this->invokeProtectedMethod(
            $this->observer,
            'spanSetAttributes',
            [$this->span, 'neuron', ['nested' => 42]],
        );
    }

    // ── getActiveSpans ──────────────────────────────────────────────

    public function testGetActiveSpansReturnsAllSpanTypes(): void
    {
        $agentSpan = $this->createMock(SpanInterface::class);
        $toolCallSpan = $this->createMock(SpanInterface::class);
        $ragSpan = $this->createMock(SpanInterface::class);
        $workflowSpan = $this->createMock(SpanInterface::class);

        $refAgent = new \ReflectionProperty(Observer::class, 'agentSpans');
        $refAgent->setValue($this->observer, ['agent' => $agentSpan]);

        $refTools = new \ReflectionProperty(Observer::class, 'toolCalls');
        $refTools->setValue($this->observer, ['tool' => $toolCallSpan]);

        $refSpans = new \ReflectionProperty(Observer::class, 'spans');
        $refSpans->setValue($this->observer, ['rag' => $ragSpan, 'wf' => $workflowSpan]);

        $result = $this->invokeProtectedMethod($this->observer, 'getActiveSpans', []);

        $this->assertCount(4, $result);
        $this->assertContains($agentSpan, $result);
        $this->assertContains($toolCallSpan, $result);
        $this->assertContains($ragSpan, $result);
        $this->assertContains($workflowSpan, $result);
    }

    public function testGetActiveSpansReturnsEmptyArrayWhenNoSpans(): void
    {
        $result = $this->invokeProtectedMethod($this->observer, 'getActiveSpans', []);

        $this->assertSame([], $result);
    }

    // ── Workflow events ─────────────────────────────────────────────

    public function testWorkflowStartCreatesSpan(): void
    {
        $workflow = new class {};

        $this->tracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->with($this->stringContains('workflow'))
            ->willReturn($this->spanBuilder);

        $this->observer->workflowStart($workflow, 'workflow-start', new WorkflowStart([]));
    }

    public function testWorkflowEndEndsSpan(): void
    {
        $workflow = new class {};
        $key = $workflow::class;

        $ref = new \ReflectionProperty(Observer::class, 'spans');
        $ref->setValue($this->observer, [$key => $this->span]);

        $this->span->expects($this->once())->method('end');

        $this->observer->workflowEnd($workflow, 'workflow-end', new WorkflowEnd(new WorkflowState()));
    }

    public function testWorkflowEndWithoutMatchingSpanReturnsEarly(): void
    {
        $workflow = new class {};
        $this->span->expects($this->never())->method('end');

        $this->observer->workflowEnd($workflow, 'workflow-end', new WorkflowEnd(new WorkflowState()));
    }

    public function testWorkflowNodeStartCreatesSpan(): void
    {
        $state = new WorkflowState();
        $state->set('key', 'value');

        $this->tracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->with('neuron.ai.workflow.some_node')
            ->willReturn($this->spanBuilder);

        $this->span
            ->expects($this->once())
            ->method('setAttribute')
            ->with('neuron.Before.key', 'value');

        $this->observer->workflowNodeStart(
            new \stdClass(),
            'workflow-node-start',
            new WorkflowNodeStart('some_node', $state),
        );
    }

    public function testWorkflowNodeEndEndsSpan(): void
    {
        $state = new WorkflowState();

        $nodeSpan = $this->createMock(SpanInterface::class);

        $ref = new \ReflectionProperty(Observer::class, 'spans');
        $ref->setValue($this->observer, ['ending_node' => $nodeSpan]);

        $nodeSpan->expects($this->once())->method('end');

        $this->observer->workflowNodeEnd(
            new \stdClass(),
            'workflow-node-end',
            new WorkflowNodeEnd('ending_node', $state),
        );
    }

    // ── Inference events ────────────────────────────────────────────

    public function testInferenceStartCreatesSpan(): void
    {
        $message = new UserMessage('query');

        $this->tracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->with('neuron.ai.inference.inference(UserMessage)')
            ->willReturn($this->spanBuilder);

        $this->observer->inferenceStart(new \stdClass(), 'inference-start', new InferenceStart($message));
    }

    public function testInferenceStopEndsSpan(): void
    {
        $message = new UserMessage('query');
        $response = new AssistantMessage('answer');

        $ref = new \ReflectionProperty(Observer::class, 'inference');
        $ref->setValue($this->observer, $this->span);

        $this->span->expects($this->once())->method('end');

        $this->observer->inferenceStop(
            new \stdClass(),
            'inference-stop',
            new InferenceStop($message, $response),
        );
    }

    public function testInferenceStopWithoutActiveSpanReturnsEarly(): void
    {
        $this->span->expects($this->never())->method('end');

        $this->observer->inferenceStop(
            new \stdClass(),
            'inference-stop',
            new InferenceStop(new UserMessage('q'), new AssistantMessage('a')),
        );
    }

    // ── Message events ──────────────────────────────────────────────

    public function testMessageSavingCreatesSpan(): void
    {
        $message = new UserMessage('hello');

        $this->tracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->with('neuron.ai.chathistory.save_message(UserMessage)')
            ->willReturn($this->spanBuilder);

        $this->observer->messageSaving(new \stdClass(), 'message-saving', new MessageSaving($message));
    }

    public function testMessageSavedEndsSpan(): void
    {
        $message = new UserMessage('hello');

        $ref = new \ReflectionProperty(Observer::class, 'message');
        $ref->setValue($this->observer, $this->span);

        $this->span->expects($this->once())->method('end');

        $this->observer->messageSaved(new \stdClass(), 'message-saved', new MessageSaved($message));
    }

    public function testMessageSavedWithoutActiveSpanReturnsEarly(): void
    {
        $this->span->expects($this->never())->method('end');

        $this->observer->messageSaved(new \stdClass(), 'message-saved', new MessageSaved(new UserMessage('x')));
    }

    // ── Helper ──────────────────────────────────────────────────────

    /**
     * @param array<int, mixed> $args
     */
    private function invokeProtectedMethod(object $object, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($object, $method);
        return $ref->invoke($object, ...$args);
    }
}
