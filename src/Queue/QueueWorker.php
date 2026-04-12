<?php

declare(strict_types=1);

namespace App\Queue;

use App\Services\RedisClientInterface;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class QueueWorker
{
    private bool $running = true;

    public function __construct(
        private readonly QueueBackendInterface $queueBackend,
        private readonly QueueJobFactory $queueJobFactory,
        private readonly RedisClientInterface $redisClient,
        private readonly Logger $logger,
    ) {
    }

    public function requestStop(): void
    {
        $this->running = false;
    }

    public function run(
        QueueWorkerOptions $queueWorkerOptions,
        string $workerId,
        OutputInterface $output,
    ): int {
        $workerState = new WorkerState();

        $this->logger->info('Queue worker started', [
            'worker_id' => $workerId,
            'queue' => $queueWorkerOptions->queueName,
        ]);

        while ($this->running && ! $this->hasReachedRuntimeLimit($queueWorkerOptions, $workerState)) {
            $workerState->incrementLoopCount();

            if (! $this->executeWorkCycle($queueWorkerOptions, $workerId, $output, $workerState)) {
                break;
            }

            pcntl_signal_dispatch();
        }

        if ($this->hasReachedRuntimeLimit($queueWorkerOptions, $workerState)) {
            $this->logRuntimeLimitReached($workerId, $workerState);
        }

        $this->logWorkerStopping($workerId, $workerState);

        return $workerState->getProcessedJobs();
    }

    private function executeWorkCycle(
        QueueWorkerOptions $queueWorkerOptions,
        string $workerId,
        OutputInterface $output,
        WorkerState $workerState,
    ): bool {
        $job = $this->reserveJob($queueWorkerOptions, $workerId, $output);

        if (! $job instanceof \App\Queue\QueueMessage) {
            return ! $queueWorkerOptions->once;
        }

        $this->processJobWithLogging($job, $workerId, $output, $workerState);

        return ! $queueWorkerOptions->once;
    }

    private function reserveJob(
        QueueWorkerOptions $queueWorkerOptions,
        string $workerId,
        OutputInterface $output,
    ): ?QueueMessage {
        try {
            return $this->queueBackend->reserveNextAvailable($queueWorkerOptions->queueName, $queueWorkerOptions->timeout);
        } catch (Throwable $throwable) {
            $this->handleReserveError($throwable, $workerId, $output);

            return null;
        }
    }

    private function handleReserveError(Throwable $throwable, string $workerId, OutputInterface $output): void
    {
        $this->logger->error('Failed to reserve job', [
            'worker_id' => $workerId,
            'error' => $throwable->getMessage(),
            'class' => $throwable::class,
        ]);
        $output->writeln(sprintf('<error>Reserve error: %s</error>', $throwable->getMessage()));
        $this->attemptRedisReconnection($workerId);
    }

    private function attemptRedisReconnection(string $workerId): void
    {
        try {
            $this->logger->info('Attempting Redis reconnection after error', ['worker_id' => $workerId]);
            $this->redisClient->reconnect();
            $this->logger->info('Redis reconnection successful', ['worker_id' => $workerId]);
        } catch (Throwable $throwable) {
            $this->logger->error('Redis reconnection failed', [
                'worker_id' => $workerId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function processJobWithLogging(
        QueueMessage $queueMessage,
        string $workerId,
        OutputInterface $output,
        WorkerState $workerState,
    ): void {
        $this->logger->info('Processing job', [
            'worker_id' => $workerId,
            'job_id' => $queueMessage->id,
            'job_class' => $queueMessage->jobClass,
        ]);

        $workerState->incrementProcessedJobs();
        $this->processJob($queueMessage, $output);
    }

    private function logRuntimeLimitReached(string $workerId, WorkerState $workerState): void
    {
        $this->logger->info('Queue worker reached runtime limit', [
            'worker_id' => $workerId,
            'processed_jobs' => $workerState->getProcessedJobs(),
            'loop_count' => $workerState->getLoopCount(),
        ]);
    }

    private function logWorkerStopping(string $workerId, WorkerState $workerState): void
    {
        $this->logger->info('Queue worker stopping', [
            'worker_id' => $workerId,
            'processed_jobs' => $workerState->getProcessedJobs(),
            'loop_count' => $workerState->getLoopCount(),
            'running' => $this->running,
        ]);
    }

    private function processJob(QueueMessage $queueMessage, OutputInterface $output): void
    {
        $queueDoer = $this->queueJobFactory->createQueueDoer($queueMessage);
        $queueDoer->handle($queueMessage->payload);

        $this->queueBackend->delete($queueMessage);

        $this->logger->info('Queue job processed', [
            'job_id' => $queueMessage->id,
            'job_class' => $queueMessage->jobClass,
        ]);
        $output->writeln(sprintf('<info>Processed queue job %s</info>', $queueMessage->id));
    }

    private function hasReachedRuntimeLimit(
        QueueWorkerOptions $queueWorkerOptions,
        WorkerState $workerState,
    ): bool {
        if ($queueWorkerOptions->maxJobs > 0 && $workerState->getProcessedJobs() >= $queueWorkerOptions->maxJobs) {
            return true;
        }

        return $queueWorkerOptions->maxTime > 0 && (time() - $workerState->getStartedAt()) >= $queueWorkerOptions->maxTime;
    }
}
