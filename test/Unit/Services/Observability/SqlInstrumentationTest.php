<?php

declare(strict_types=1);

namespace Test\Unit\Services\Observability;

use App\Services\Observability\SqlInstrumentation;
use App\Services\Settings;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SqlInstrumentationTest extends TestCase
{
    private InMemoryExporter $inMemoryExporter;

    private TracerProvider $tracerProvider;

    private ScopeInterface $scope;

    protected function setUp(): void
    {
        $this->inMemoryExporter = new InMemoryExporter();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->inMemoryExporter));
        $this->scope = Configurator::create()->withTracerProvider($this->tracerProvider)->activate();
    }

    protected function tearDown(): void
    {
        $this->scope->detach();
        $this->tracerProvider->shutdown();
    }

    public function testExecutionIsChildWithTechnicalContextAndNativeDuration(): void
    {
        $span = $this->tracerProvider->getTracer('test')->spanBuilder('queue.process')->startSpan();
        $scope = $span->storeInContext(Context::getCurrent())->with(SqlInstrumentation::contextKey(), 'job')->activate();
        try {
            $result = new SqlInstrumentation(true)->measure(' SELECT ? ', 'pdo_pgsql', static function (): int {
                self::assertNotSame('', Span::getCurrent()->getContext()->getSpanId());
                usleep(20000);
                return 42;
            });
            self::assertSame(42, $result);
            self::assertSame($span, Span::getCurrent());
        } finally {
            $scope->detach();
            $span->end();
        }

        [$sql] = $this->inMemoryExporter->getSpans();
        self::assertSame('SELECT', $sql->getName());
        self::assertSame('claire.sql', $sql->getInstrumentationScope()->getName());
        self::assertSame(SpanKind::KIND_CLIENT, $sql->getKind());
        self::assertSame($span->getContext()->getSpanId(), $sql->getParentSpanId());
        self::assertSame('job', $sql->getAttributes()->get('claire.sql.context'));
        self::assertSame('postgresql', $sql->getAttributes()->get('db.system.name'));
        self::assertSame(' SELECT ? ', $sql->getAttributes()->get('db.query.text'));
        self::assertGreaterThanOrEqual(20000000, $sql->getEndEpochNanos() - $sql->getStartEpochNanos());
    }

    public function testOriginalExceptionIsRethrownWithoutMessageOrStackTrace(): void
    {
        $exception = new RuntimeException('secret-bound-value');
        $context = Context::getCurrent();
        try {
            new SqlInstrumentation(true)->measure("INSERT INTO t VALUES (?, 'visible literal')", 'sqlite',
                static fn () => throw $exception);
            self::fail('Expected original exception');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($exception, $runtimeException);
        }

        self::assertSame($context, Context::getCurrent());
        [$span] = $this->inMemoryExporter->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        self::assertSame('', $span->getStatus()->getDescription());
        self::assertSame(RuntimeException::class, $span->getAttributes()->get('error.type'));
        self::assertSame([], $span->getEvents());
        $attributes = json_encode($span->getAttributes()->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-bound-value', $attributes);
        self::assertStringContainsString('visible literal', $attributes);
    }

    public function testTruncationRepairsUtf8AndHonorsByteLimit(): void
    {
        foreach ([0, 1, 8, 9, 10, 11, 12, 16384] as $limit) {
            new SqlInstrumentation(true, $limit)->measure("SELECT '\xF0\x9F\x98\x80\xFF'", 'sqlite', static fn (): null => null);
            $span = $this->inMemoryExporter->getSpans()[array_key_last($this->inMemoryExporter->getSpans())];
            $text = $span->getAttributes()->get('db.query.text');
            self::assertSame(1, preg_match('//u', $text));
            self::assertLessThanOrEqual($limit, strlen($text));
            self::assertSame($limit < 16, $span->getAttributes()->get('claire.sql.query.truncated'));
        }
    }

    public function testSettingsDisabledNoopAndUnknownOperation(): void
    {
        self::assertSame(false, SqlInstrumentation::fromSettings(new Settings([]))
            ->measure('SELECT 1', 'sqlite', static fn (): false => false));
        self::assertSame([], $this->inMemoryExporter->getSpans());
        $scope = Configurator::createNoop()->activate();
        try {
            self::assertSame(1, new SqlInstrumentation(true)->measure('SELECT 1', 'sqlite', static fn (): int => 1));
        } finally {
            $scope->detach();
        }

        $settings = new Settings(['observability' => ['sql' => ['enabled' => true, 'maxQueryTextBytes' => 5]]]);
        SqlInstrumentation::fromSettings($settings)->measure('/* comment */ SELECT 1', 'sqlite', static fn (): null => null);
        [$span] = $this->inMemoryExporter->getSpans();
        self::assertSame('SQL', $span->getName());
        self::assertNull($span->getAttributes()->get('db.operation.name'));
        self::assertSame('/* co', $span->getAttributes()->get('db.query.text'));
    }

    public function testDefaultLimitAndGlobalSamplingArePreserved(): void
    {
        new SqlInstrumentation(true)->measure('SELECT ' . str_repeat('a', 20000), 'sqlite', static fn (): null => null);
        [$span] = $this->inMemoryExporter->getSpans();
        self::assertSame(16384, strlen($span->getAttributes()->get('db.query.text')));
        self::assertTrue($span->getAttributes()->get('claire.sql.query.truncated'));
        $scope = Configurator::create()->withTracerProvider(new TracerProvider(
            new SimpleSpanProcessor($this->inMemoryExporter), new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler()
        ))->activate();
        try {
            self::assertSame(9, new SqlInstrumentation(true)->measure('SELECT 9', 'sqlite', static fn (): int => 9));
        } finally {
            $scope->detach();
        }

        self::assertCount(1, $this->inMemoryExporter->getSpans());
    }

    public function testTelemetryFailuresNeverRepeatOrMaskExecution(): void
    {
        foreach (['provider', 'start', 'activate', 'status', 'detach', 'end'] as $failure) {
            foreach ([false, true] as $sqlFails) {
                $provider = $this->createStub(TracerProviderInterface::class);
                $tracer = $this->createStub(TracerInterface::class);
                $builder = $this->createStub(SpanBuilderInterface::class);
                $span = $this->createStub(SpanInterface::class);
                $scope = $this->createStub(ScopeInterface::class);
                $error = new RuntimeException('telemetry failure');
                $provider->method('getTracer')->willReturnCallback(static fn (): TracerInterface =>
                    $failure === 'provider' ? throw $error : $tracer);
                $tracer->method('spanBuilder')->willReturn($builder);
                $builder->method('setSpanKind')->willReturnSelf();
                $builder->method('setAttributes')->willReturnSelf();
                $builder->method('startSpan')->willReturnCallback(static fn (): SpanInterface =>
                    $failure === 'start' ? throw $error : $span);
                $span->method('activate')->willReturnCallback(static fn (): ScopeInterface =>
                    $failure === 'activate' ? throw $error : $scope);
                $span->method('setStatus')->willReturnCallback(static fn (): SpanInterface =>
                    $failure === 'status' ? throw $error : $span);
                $scope->method('detach')->willReturnCallback(static fn (): int =>
                    $failure === 'detach' ? throw $error : 0);
                $span->method('end')->willReturnCallback(static function () use ($failure, $error): void {
                    if ($failure === 'end') {
                        throw $error;
                    }
                });
                $configured = Configurator::create()->withTracerProvider($provider)->activate();
                $calls = 0;
                $original = new RuntimeException('SQL failure');
                try {
                    $result = new SqlInstrumentation(true)->measure('SELECT ?', 'sqlite',
                        static function () use (&$calls, $sqlFails, $original): int {
                            ++$calls;
                            return $sqlFails ? throw $original : 7;
                        });
                    self::assertFalse($sqlFails);
                    self::assertSame(7, $result);
                } catch (RuntimeException $caught) {
                    self::assertTrue($sqlFails);
                    self::assertSame($original, $caught);
                } finally {
                    $configured->detach();
                }

                self::assertSame(1, $calls, $failure);
            }
        }
    }
}
