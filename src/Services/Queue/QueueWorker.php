<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Brain\Observability\HasInstrumentation;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as Logger;
use RuntimeException;
use Throwable;

final class QueueWorker
{
    use HasInstrumentation;

    private bool $running = true;

    private readonly int $startedAt;

    private int $processedJobs = 0;

    private int $loopCount = 0;

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
        $job = $this->reserveJob($queueWorkerOptions, $workerId);

        if (! $job instanceof QueueMessage) {
            return;
        }

        $this->otelStartSpan('queue.process');
        $this->activeSpan->setAttribute('messaging.system', 'redis');
        $this->activeSpan->setAttribute('messaging.destination', $queueWorkerOptions->queueName);
        $this->activeSpan->setAttribute('messaging.message.id', $job->id);
        $this->activeSpan->setAttribute('messaging.job_class', $job->jobClass);
        $this->activeSpan->setAttribute('worker.id', $workerId);

        try {
            $this->logger->info('Processing job', [
                'worker_id' => $workerId,
                'job_id' => $job->id,
                'job_class' => $job->jobClass,
            ]);

            $this->processedJobs++;
            $this->processJob($job);

            $this->activeSpan?->setStatus(StatusCode::STATUS_OK);
        } catch (Throwable $throwable) {
            if ($this->activeSpan instanceof \OpenTelemetry\API\Trace\SpanInterface) {
                $this->activeSpan->setStatus(StatusCode::STATUS_ERROR, $throwable->getMessage());
                $this->activeSpan->recordException($throwable);
            }

            $this->logger->error('Failed to execute job', [
                'worker_id' => $workerId,
                'error' => $throwable,
                'job_id' => $job->id,
                'job_class' => $job->jobClass,
            ]);
        }

        $this->queueBackend->delete($job);
        $this->otelStopSpan();
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

        // Comme on est dans un worker on force le flush des logs
        $loggerProvider = \OpenTelemetry\API\Globals::loggerProvider();
        if (method_exists($loggerProvider, 'forceFlush')) {
            $loggerProvider->forceFlush();
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
