<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Services\Observability\SqlInstrumentation;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Throwable;

final class QueueWorker
{
    private bool $running = true;

    private readonly int $startedAt;

    private int $processedJobs = 0;

    private int $loopCount = 0;

    private int $lastRecoveryAt = 0;

    public function __construct(
        private readonly QueueBackendInterface $queueBackend,
        private readonly ContainerInterface $container,
        private readonly Logger $logger,
    ) {
        $this->startedAt = time();
    }

    public function requestStop(): void
    {
        $this->running = false;
    }

    public function run(
        QueueWorkerOptions $queueWorkerOptions,
        string $workerId,
    ): int {
        $this->logger->info('Queue worker started', [
            'worker_id' => $workerId,
            'queue' => $queueWorkerOptions->queueName,
            'timeout' => $queueWorkerOptions->timeout,
            'max_jobs' => $queueWorkerOptions->maxJobs,
            'max_time' => $queueWorkerOptions->maxTime,
            'started_at' => $this->startedAt,
        ]);

        while ($this->running && ! $this->hasReachedRuntimeLimit($queueWorkerOptions)) {
            $this->loopCount++;

            $this->executeWorkCycle($queueWorkerOptions, $workerId);

            pcntl_signal_dispatch();
        }

        if ($this->hasReachedRuntimeLimit($queueWorkerOptions)) {
            $this->logger->info('Queue worker reached runtime limit', [
                'worker_id' => $workerId,
                'processed_jobs' => $this->processedJobs,
                'loop_count' => $this->loopCount,

            ]);
        }

        $this->logger->info('Queue worker stopping', [
            'worker_id' => $workerId,
            'processed_jobs' => $this->processedJobs,
            'loop_count' => $this->loopCount,
            'running' => $this->running,
        ]);
        $this->flushTelemetry();
        return $this->processedJobs;
    }

    public function createQueueDoer(QueueMessage $queueMessage): QueueDoer
    {
        $jobClass = $queueMessage->jobClass;

        if (! class_exists($jobClass)) {
            throw new RuntimeException(sprintf('Queue job class "%s" does not exist', $jobClass));
        }

        if (! is_a($jobClass, QueueDoer::class, true)) {
            throw new RuntimeException(sprintf('Queue job class "%s" must implement %s', $jobClass, QueueDoer::class));
        }

        if (! method_exists($jobClass, 'make')) {
            throw new RuntimeException(sprintf('Queue job class "%s" must define static make()', $jobClass));
        }

        return $jobClass::make($this->container);
    }

    private function executeWorkCycle(
        QueueWorkerOptions $queueWorkerOptions,
        string $workerId,
    ): void {
        if (time() - $this->lastRecoveryAt >= 30) {
            $this->lastRecoveryAt = time();
            try {
                if ($this->queueBackend instanceof RedisQueueBackend) {
                    $this->queueBackend->withMaintenance($this->maintain(...));
                } else {
                    $cursor = '0';
                    $recoveryCursor = null;
                    $this->maintain($cursor, $recoveryCursor);
                }
            } catch (Throwable $error) {
                $this->logger->error('Periodic maintenance coordination failed', ['exception' => $error]);
            }
        }

        $this->executeJobCycle($queueWorkerOptions, $workerId);
    }

    private function maintain(string &$failedCursor, ?string &$recoveryCursor): void
    {
        $span = $this->startSpan('queue.maintenance', Context::getRoot(), SpanKind::KIND_INTERNAL);
        $scope = $this->activateSpan($span, Context::getRoot(), 'maintenance');
        try {
            $this->performMaintenance($failedCursor, $recoveryCursor, $span);
        } finally {
            $scope->detach();
            $this->finishSpan($span);
        }
    }

    private function performMaintenance(
        string &$failedCursor,
        ?string &$recoveryCursor,
        SpanInterface $span,
    ): void
    {
        try {
            if ($this->container->has(\App\Services\ChatTurnRecovery::class)) {
                $recovery = $this->container->get(\App\Services\ChatTurnRecovery::class);
                $recoveryCursor = $recovery->recover(cursor: $recoveryCursor)['cursor'];
                if ($this->queueBackend instanceof RedisQueueBackend) {
                    foreach ($this->queueBackend->failedMessages($failedCursor) as $queueMessage) {
                        try {
                            $recovery->failed($queueMessage);
                        } catch (\App\Services\ChatGenerationBusyException) {
                            // Active holders, not elapsed time, determine abandonment.
                            continue;
                        } catch (Throwable $error) {
                            $this->markSpanError($span, $error);
                            $this->logger->error('Terminal chat recovery failed', ['exception' => $error]);
                        }
                    }
                }
            }
        } catch (Throwable $throwable) {
            $this->markSpanError($span, $throwable);
            $this->logger->error('Periodic chat recovery failed', ['exception' => $throwable]);
        }

        try {
            if ($this->container->has(\App\Services\Settings::class)) {
                $settings = $this->container->get(\App\Services\Settings::class);
                if ($settings->get('llm.semanticMemory.enabled', false) === true
                    || $this->container->get(\Doctrine\DBAL\Connection::class)->createSchemaManager()->tablesExist([
                        'semantic_memory_preference', 'semantic_memory_excerpt',
                    ])) {
                    $this->container->get(\App\Services\SemanticMemoryService::class)->sweep(5);
                }

                if ($settings->get('llm.stop.enabled', false) === true) {
                    $retention = max(2592000, (int) $settings->get('queue.deduplicationSeconds', 604800) + 86400);
                    $this->container->get(\App\Services\ChatStopRequests::class)->cleanup(time() - $retention);
                }
            }
        } catch (Throwable $throwable) {
            $this->markSpanError($span, $throwable);
            // Consent and source validity remain authoritative while asynchronous work retries.
            $this->logger->warning('Periodic memory/stop maintenance failed', ['exception' => $throwable::class]);
        }
    }

    private function executeJobCycle(QueueWorkerOptions $queueWorkerOptions, string $workerId): void
    {
        $job = $this->reserveJob($queueWorkerOptions, $workerId);

        if (! $job instanceof QueueMessage) {
            if ($queueWorkerOptions->timeout <= 0) {
                usleep(100_000);
            }

            return;
        }

        $carrier = array_filter(
            array_intersect_key($job->metadata, array_flip(['traceparent', 'tracestate'])),
            is_string(...),
        );
        $context = TraceContextPropagator::getInstance()->extract($carrier, context: Context::getRoot());
        $span = $this->startSpan('queue.process', $context, SpanKind::KIND_CONSUMER, [
            'messaging.system' => 'redis',
            'messaging.destination' => $queueWorkerOptions->queueName,
            'messaging.message.id' => $job->id,
            'messaging.job_class' => $job->jobClass,
            'worker.id' => $workerId,
        ]);
        $scope = $this->activateSpan($span, $context, 'job');

        try {
            $this->logger->info('Processing job', [
                'worker_id' => $workerId,
                'job_id' => $job->id,
                'job_class' => $job->jobClass,
            ]);

            $this->processedJobs++;
            if ($this->queueBackend instanceof LeasedQueueBackendInterface) {
                $this->queueBackend->withLease($job, fn () => $this->processJob($job));
            } else {
                $this->processJob($job);
            }

            $this->queueBackend->delete($job);
        } catch (Throwable $throwable) {
            $this->markSpanError($span, $throwable);

            $this->logger->error('Failed to execute job', [
                'worker_id' => $workerId,
                'error' => $throwable,
                'job_id' => $job->id,
                'job_class' => $job->jobClass,
            ]);
            try {
                if ($this->queueBackend instanceof LeasedQueueBackendInterface
                    && $throwable instanceof NonRetryableJobException) {
                    $this->queueBackend->fail($job);
                } elseif ($this->queueBackend instanceof LeasedQueueBackendInterface
                    && $throwable instanceof \App\Services\ChatGenerationBusyException) {
                    $this->queueBackend->defer($job);
                } else {
                    $this->queueBackend->release($job);
                }

                if ($this->queueBackend instanceof RedisQueueBackend && $this->queueBackend->isFailed($job)
                    && $this->container->has(\App\Services\ChatTurnRecovery::class)) {
                    $this->container->get(\App\Services\ChatTurnRecovery::class)->failed($job);
                }
            } catch (Throwable $releaseError) {
                $this->logger->error('Failed to release job; lease recovery required', [
                    'job_id' => $job->id,
                    'error' => $releaseError,
                ]);
            }
        } finally {
            $scope->detach();
            $this->finishSpan($span);
        }
    }

    private function reserveJob(
        QueueWorkerOptions $queueWorkerOptions,
        string $workerId,
    ): ?QueueMessage {
        try {
            return $this->queueBackend->reserveNextAvailable($queueWorkerOptions->queueName, $queueWorkerOptions->timeout);
        } catch (Throwable $throwable) {
            $this->logger->error('Failed to reserve job', [
                'worker_id' => $workerId,
                'error' => $throwable->getMessage(),
                'class' => $throwable::class,
            ]);
            usleep(250_000);
            return null;
        }
    }

    private function processJob(QueueMessage $queueMessage): void
    {
        // Clear EntityManager to avoid memory leaks and stale data in workers
        if ($this->container->has(\Doctrine\ORM\EntityManagerInterface::class)) {
            $this->container->get(\Doctrine\ORM\EntityManagerInterface::class)->clear();
        }

        $queueDoer = $this->createQueueDoer($queueMessage);
        $queueDoer->handle($queueMessage->payload);

        $this->logger->info('Queue job processed', [
            'job_id' => $queueMessage->id,
            'job_class' => $queueMessage->jobClass,
        ]);
    }

    /** @param array<string, string> $attributes */
    private function startSpan(string $name, ContextInterface $context, int $kind, array $attributes = []): SpanInterface
    {
        try {
            return Globals::tracerProvider()->getTracer('claire.queue')->spanBuilder($name)
                ->setParent($context)->setSpanKind($kind)->setAttributes($attributes)->startSpan();
        } catch (Throwable) {
            return Span::getInvalid();
        }
    }

    private function activateSpan(SpanInterface $span, ContextInterface $context, string $marker): ScopeInterface
    {
        try {
            // Preserve configured providers, never baggage or arbitrary ambient values.
            $context = Configurator::create()
                ->withTracerProvider(Globals::tracerProvider())
                ->withLoggerProvider(Globals::loggerProvider())
                ->withMeterProvider(Globals::meterProvider())
                ->storeInContext($context);
            $context = $span->storeInContext($context);
        } catch (Throwable) {
            $context = Span::getInvalid()->storeInContext($context);
        }

        return $context->with(SqlInstrumentation::contextKey(), $marker)->activate();
    }

    private function markSpanError(SpanInterface $span, Throwable $throwable): void
    {
        try {
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->setAttribute('error.type', $throwable::class);
        } catch (Throwable) {
            // Telemetry must not alter queue transitions.
        }
    }

    private function finishSpan(SpanInterface $span): void
    {
        try {
            $span->end();
        } catch (Throwable) {
            // Export failures must not prevent the next job.
        } finally {
            $this->flushTelemetry();
        }
    }

    private function flushTelemetry(): void
    {
        // One attempt per provider. SDK batch exports ignore cancellation, so
        // transport timeouts apply per request, not as a hard flush deadline.
        foreach (['tracerProvider', 'loggerProvider'] as $accessor) {
            try {
                $provider = Globals::$accessor();
                if ($provider instanceof \OpenTelemetry\SDK\Trace\TracerProviderInterface
                    || $provider instanceof \OpenTelemetry\SDK\Logs\LoggerProviderInterface) {
                    $provider->forceFlush();
                }
            } catch (Throwable) {
                // Best effort only; logging here could recursively trigger another export.
            }
        }
    }

    private function hasReachedRuntimeLimit(
        QueueWorkerOptions $queueWorkerOptions
    ): bool {
        if ($queueWorkerOptions->maxJobs > 0 && $this->processedJobs >= $queueWorkerOptions->maxJobs) {
            return true;
        }

        return $queueWorkerOptions->maxTime > 0 && (time() - $this->startedAt) >= $queueWorkerOptions->maxTime;
    }
}
