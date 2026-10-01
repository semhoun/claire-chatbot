<?php

declare(strict_types=1);

namespace App\Test\Unit\Queue;

use App\Services\Observability\SqlInstrumentation;
use App\Services\Queue\QueueBackendInterface;
use App\Services\Queue\QueueDoer;
use App\Services\Queue\QueueMessage;
use App\Services\Queue\QueueWorker;
use App\Services\Queue\QueueWorkerOptions;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

final class QueueWorkerTelemetryTest extends TestCase
{
    public function testConsecutiveJobsUseIsolatedParentsThroughLogsAndFinalization(): void
    {
        $inMemoryExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($inMemoryExporter));
        $scope = Configurator::create()->withTracerProvider($tracerProvider)->activate();
        $ambient = $tracerProvider->getTracer('test')->spanBuilder('ambient')->startSpan();
        $contextKey = Context::createKey('test.ambient');
        $ambientScope = $ambient->storeInContext(Context::getCurrent()->with($contextKey, 'private'))->activate();
        $before = Context::getCurrent();
        $messages = [
            new QueueMessage('one', TelemetryWorkerJob::class, [], 'test', [
                'traceparent' => '00-11111111111111111111111111111111-1111111111111111-01',
                'tracestate' => 'vendor=value',
            ]),
            new QueueMessage('two', TelemetryWorkerJob::class, ['fail' => true], 'test', [
                'traceparent' => '00-22222222222222222222222222222222-2222222222222222-01',
            ]),
            new QueueMessage('legacy', TelemetryWorkerJob::class, [], 'test'),
            new QueueMessage('invalid', TelemetryWorkerJob::class, [], 'test', [
                'traceparent' => 'invalid', 'tracestate' => ['invalid'],
            ]),
        ];
        $backend = $this->createMock(QueueBackendInterface::class);
        $backend->method('reserveNextAvailable')->willReturnOnConsecutiveCalls(...$messages);
        $finalized = [];
        $ambientValues = [];
        $finalize = static function (QueueMessage $queueMessage) use (&$finalized, &$ambientValues, $contextKey): void {
            self::assertSame('job', Context::getCurrent()->get(SqlInstrumentation::contextKey()));
            $finalized[$queueMessage->id] = Span::getCurrent()->getContext()->getSpanId();
            $ambientValues[] = Context::getCurrent()->get($contextKey);
            if ($queueMessage->id === 'two') {
                throw new RuntimeException('Release failed');
            }
        };
        $backend->expects(self::exactly(3))->method('delete')->willReturnCallback($finalize);
        $backend->expects(self::once())->method('release')->willReturnCallback($finalize);
        $logger = $this->createStub(LoggerInterface::class);
        $logged = [];
        $capture = static function (string $message, array $context) use (&$logged): void {
            if (isset($context['job_id'])) {
                self::assertSame('job', Context::getCurrent()->get(SqlInstrumentation::contextKey()));
                $logged[$context['job_id']][] = Span::getCurrent()->getContext()->getSpanId();
            }
        };
        $logger->method('info')->willReturnCallback($capture);
        $logger->method('error')->willReturnCallback($capture);
        try {
            $queueWorker = new QueueWorker($backend, $this->createStub(ContainerInterface::class), $logger);
            self::assertSame(4, $queueWorker->run(new QueueWorkerOptions('test', 0, 4, 10), 'worker'));
            self::assertSame($before, Context::getCurrent());
            self::assertSame([null, null, null, null], $ambientValues);
            $jobs = array_values(array_filter($inMemoryExporter->getSpans(), static fn ($span): bool =>
                $span->getName() === 'queue.process'));
            self::assertCount(4, $jobs);
            self::assertSame('11111111111111111111111111111111', $jobs[0]->getContext()->getTraceId());
            self::assertSame('1111111111111111', $jobs[0]->getParentContext()->getSpanId());
            self::assertSame('vendor=value', (string) $jobs[0]->getContext()->getTraceState());
            self::assertSame('22222222222222222222222222222222', $jobs[1]->getContext()->getTraceId());
            self::assertSame('2222222222222222', $jobs[1]->getParentContext()->getSpanId());
            self::assertSame(StatusCode::STATUS_ERROR, $jobs[1]->getStatus()->getCode());
            self::assertCount(0, $jobs[1]->getEvents());
            self::assertFalse($jobs[2]->getParentContext()->isValid());
            self::assertFalse($jobs[3]->getParentContext()->isValid());
            self::assertNotSame($jobs[2]->getContext()->getTraceId(), $jobs[3]->getContext()->getTraceId());
            $children = array_values(array_filter($inMemoryExporter->getSpans(), static fn ($span): bool =>
                $span->getName() === 'job.child'));
            self::assertCount(4, $children);
            foreach ($jobs as $index => $job) {
                $id = $messages[$index]->id;
                self::assertSame(SpanKind::KIND_CONSUMER, $job->getKind());
                self::assertSame($job->getContext()->getSpanId(), $finalized[$id]);
                self::assertSame([$finalized[$id]], array_values(array_unique($logged[$id])));
                self::assertSame($finalized[$id], $children[$index]->getParentContext()->getSpanId());
                self::assertSame('job', $children[$index]->getAttributes()->get('claire.sql.context'));
            }
        } finally {
            $ambientScope->detach();
            $ambient->end();
            $scope->detach();
        }
    }

    public function testMaintenanceFailureEndsRootAndRestoresContextAndCursors(): void
    {
        $inMemoryExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($inMemoryExporter));
        $scope = Configurator::create()->withTracerProvider($tracerProvider)->activate();
        $span = $tracerProvider->getTracer('test')->spanBuilder('ambient')->startSpan();
        $ambientScope = $span->activate();
        $context = Context::getCurrent();
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static function (): bool {
            self::assertSame('maintenance', Context::getCurrent()->get(SqlInstrumentation::contextKey()));
            new SqlInstrumentation(true)->measure('SELECT 1', 'sqlite',
                static fn (): \PDOStatement|false => new \PDO('sqlite::memory:')->query('SELECT 1'));
            throw new RuntimeException('Maintenance failure');
        });
        $queueWorker = new QueueWorker($this->createStub(QueueBackendInterface::class), $container, new NullLogger());
        $failed = '17';
        $recovery = '29';
        try {
            new ReflectionMethod($queueWorker, 'maintain')->invokeArgs($queueWorker, [&$failed, &$recovery]);
            self::assertSame($context, Context::getCurrent());
            self::assertSame('17', $failed);
            self::assertSame('29', $recovery);
            $spans = $inMemoryExporter->getSpans();
            self::assertCount(3, $spans);
            $maintenance = $spans[2];
            self::assertSame('queue.maintenance', $maintenance->getName());
            self::assertFalse($maintenance->getParentContext()->isValid());
            self::assertSame(StatusCode::STATUS_ERROR, $maintenance->getStatus()->getCode());
            foreach (array_slice($spans, 0, 2) as $child) {
                self::assertSame($maintenance->getContext()->getSpanId(), $child->getParentContext()->getSpanId());
                self::assertSame('claire.sql', $child->getInstrumentationScope()->getName());
                self::assertSame('maintenance', $child->getAttributes()->get('claire.sql.context'));
                self::assertSame('SELECT 1', $child->getAttributes()->get('db.query.text'));
            }
        } finally {
            $ambientScope->detach();
            $span->end();
            $scope->detach();
        }
    }

    public function testFlushFailuresAreIsolatedAndOnlyOccurAfterSpanEnd(): void
    {
        $inMemoryExporter = new InMemoryExporter();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor($inMemoryExporter));
        $provider = $this->createMock(\OpenTelemetry\SDK\Trace\TracerProviderInterface::class);
        $provider->method('getTracer')->willReturn($tracerProvider->getTracer('test'));
        $flushSnapshots = [];
        $provider->expects(self::exactly(2))->method('forceFlush')->willReturnCallback(
            static function () use ($inMemoryExporter, &$flushSnapshots): never {
                $flushSnapshots[] = [
                    Context::getCurrent()->get(SqlInstrumentation::contextKey()),
                    array_map(static fn ($span): string => $span->getName(), $inMemoryExporter->getSpans()),
                ];
                throw new RuntimeException('Trace export unavailable');
            },
        );
        $logs = $this->createMock(\OpenTelemetry\SDK\Logs\LoggerProviderInterface::class);
        $logs->expects(self::exactly(2))->method('forceFlush')->willThrowException(
            new RuntimeException('Log export unavailable'),
        );
        $scope = Configurator::create()->withTracerProvider($provider)->withLoggerProvider($logs)->activate();
        $context = Context::getCurrent();
        $backend = $this->createMock(QueueBackendInterface::class);
        $backend->method('reserveNextAvailable')->willReturn(new QueueMessage(
            'failure', TelemetryWorkerJob::class, ['fail' => true], 'test',
        ));
        $backend->expects(self::exactly(2))->method('release');
        $queueWorker = new QueueWorker($backend, $this->createStub(ContainerInterface::class), new NullLogger());
        try {
            new ReflectionMethod($queueWorker, 'executeJobCycle')->invoke($queueWorker,
                new QueueWorkerOptions('test', 0, 1, 10), 'worker');
            new ReflectionMethod($queueWorker, 'executeJobCycle')->invoke($queueWorker,
                new QueueWorkerOptions('test', 0, 1, 10), 'worker');
            self::assertSame($context, Context::getCurrent());
            self::assertSame([
                [null, ['job.child', 'queue.process']],
                [null, ['job.child', 'queue.process', 'job.child', 'queue.process']],
            ], $flushSnapshots);
        } finally {
            $scope->detach();
        }
    }

    public function testLosingMaintenanceCoordinationCreatesNoSpan(): void
    {
        $inMemoryExporter = new InMemoryExporter();
        $scope = Configurator::create()->withTracerProvider(
            new TracerProvider(new SimpleSpanProcessor($inMemoryExporter)),
        )->activate();
        $redis = $this->createMock(\App\Services\Queue\QueueRedisConnection::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('has');
        $redisQueueBackend = new \App\Services\Queue\RedisQueueBackend($redis,
            new \App\Services\Settings(['redis' => ['prefix' => 'test:'], 'queue' => []]),
            \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $queueWorker = new QueueWorker($redisQueueBackend, $container, new NullLogger());
        $calls = 0;
        $redis->expects(self::exactly(2))->method('evaluate')->willReturnCallback(
            static function () use ($queueWorker, &$calls): int|array {
                if (++$calls === 1) {
                    return 0;
                }

                $queueWorker->requestStop();
                return [];
            },
        );
        try {
            self::assertSame(0, $queueWorker->run(new QueueWorkerOptions('test', 0, 1, 10), 'worker'));
            self::assertSame([], $inMemoryExporter->getSpans());
        } finally {
            $scope->detach();
        }
    }

    public function testTracerFailureDoesNotPreventAcknowledgementOrLeakContext(): void
    {
        $provider = $this->createMock(\OpenTelemetry\SDK\Trace\TracerProviderInterface::class);
        $provider->expects(self::once())->method('getTracer')->willThrowException(new RuntimeException('Unavailable'));
        $provider->expects(self::once())->method('forceFlush')->willReturn(false);
        $scope = Configurator::create()->withTracerProvider($provider)->activate();
        $context = Context::getCurrent();
        $backend = $this->createMock(QueueBackendInterface::class);
        $backend->method('reserveNextAvailable')->willReturn(new QueueMessage(
            'noop', NoTelemetryWorkerJob::class, [], 'test',
        ));
        $backend->expects(self::once())->method('delete');
        $backend->expects(self::never())->method('release');
        $queueWorker = new QueueWorker($backend, $this->createStub(ContainerInterface::class), new NullLogger());
        try {
            new ReflectionMethod($queueWorker, 'executeJobCycle')->invoke($queueWorker,
                new QueueWorkerOptions('test', 0, 1, 10), 'worker');
            self::assertSame($context, Context::getCurrent());
        } finally {
            $scope->detach();
        }
    }

    public function testSpanStorageAndEndFailuresDoNotChangeJobOutcome(): void
    {
        $span = $this->createMock(\OpenTelemetry\API\Trace\SpanInterface::class);
        $span->expects(self::once())->method('storeInContext')->willThrowException(new RuntimeException('Store failed'));
        $span->expects(self::once())->method('end')->willThrowException(new RuntimeException('End failed'));
        $builder = $this->createStub(\OpenTelemetry\API\Trace\SpanBuilderInterface::class);
        $builder->method('setParent')->willReturnSelf();
        $builder->method('setSpanKind')->willReturnSelf();
        $builder->method('setAttributes')->willReturnSelf();
        $builder->method('startSpan')->willReturn($span);
        $tracer = $this->createStub(\OpenTelemetry\API\Trace\TracerInterface::class);
        $tracer->method('spanBuilder')->willReturn($builder);
        $provider = $this->createMock(\OpenTelemetry\SDK\Trace\TracerProviderInterface::class);
        $provider->method('getTracer')->willReturn($tracer);
        $provider->expects(self::once())->method('forceFlush')->willReturn(true);
        $scope = Configurator::create()->withTracerProvider($provider)->activate();
        $context = Context::getCurrent();
        $backend = $this->createMock(QueueBackendInterface::class);
        $backend->method('reserveNextAvailable')->willReturn(new QueueMessage(
            'noop', NoTelemetryWorkerJob::class, [], 'test',
        ));
        $backend->expects(self::once())->method('delete');
        $backend->expects(self::never())->method('release');
        $queueWorker = new QueueWorker($backend, $this->createStub(ContainerInterface::class), new NullLogger());
        try {
            new ReflectionMethod($queueWorker, 'executeJobCycle')->invoke($queueWorker,
                new QueueWorkerOptions('test', 0, 1, 10), 'worker');
            self::assertSame($context, Context::getCurrent());
        } finally {
            $scope->detach();
        }
    }
}

final class TelemetryWorkerJob implements QueueDoer
{
    public static function make(ContainerInterface $container): self
    {
        return new self();
    }

    public function handle(array $payload): void
    {
        Globals::tracerProvider()->getTracer('test')->spanBuilder('job.child')
            ->setAttribute('claire.sql.context', Context::getCurrent()->get(SqlInstrumentation::contextKey()))
            ->startSpan()->end();
        if ($payload['fail'] ?? false) {
            throw new RuntimeException('Job failed with sensitive detail');
        }
    }
}

final class NoTelemetryWorkerJob implements QueueDoer
{
    public static function make(ContainerInterface $container): self
    {
        return new self();
    }

    public function handle(array $payload): void
    {
    }
}
