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
        $startedAt = time();
        $processedJobs = 0;
        $loopCount = 0;

        $this->logger->info('Queue worker started', [
            'worker_id' => $workerId,
            'queue' => $queueWorkerOptions->queueName,
        ]);

        while ($this->running) {
            $loopCount++;

            if ($this->hasReachedRuntimeLimit($queueWorkerOptions, $startedAt, $processedJobs)) {
                $this->logger->info('Queue worker reached runtime limit', [
                    'worker_id' => $workerId,
                    'processed_jobs' => $processedJobs,
                    'loop_count' => $loopCount,
                ]);
                break;
            }

            try {
                $job = $this->queueBackend->reserveNextAvailable(
                    $queueWorkerOptions->queueName,
                );
            } catch (Throwable $e) {
                $this->logger->error('Failed to reserve job', [
                    'worker_id' => $workerId,
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
                $output->writeln(sprintf('<error>Reserve error: %s</error>', $e->getMessage()));

                // Try to reconnect Redis on error
                try {
                    $this->logger->info('Attempting Redis reconnection after error', [
                        'worker_id' => $workerId,
                    ]);
                    $this->redisClient->reconnect();
                    $this->logger->info('Redis reconnection successful', [
                        'worker_id' => $workerId,
                    ]);
                } catch (Throwable $reconnectError) {
                    $this->logger->error('Redis reconnection failed', [
                        'worker_id' => $workerId,
                        'error' => $reconnectError->getMessage(),
                    ]);
                }

                sleep(1);
                continue;
            }

            if (! $job instanceof QueueMessage) {
                if ($queueWorkerOptions->once) {
                    break;
                }

                $this->sleepWithSignalDispatch($queueWorkerOptions->sleep);

                continue;
            }

            $this->logger->info('Processing job', [
                'worker_id' => $workerId,
                'job_id' => $job->id,
                'job_class' => $job->jobClass,
            ]);

            $processedJobs++;
            $this->processJob($job, $output);

            if ($queueWorkerOptions->once) {
                break;
            }
        }

        $this->logger->info('Queue worker stopping', [
            'worker_id' => $workerId,
            'processed_jobs' => $processedJobs,
            'loop_count' => $loopCount,
            'running' => $this->running,
        ]);

        return $processedJobs;
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
        int $startedAt,
        int $processedJobs,
    ): bool {
        if ($queueWorkerOptions->maxJobs > 0 && $processedJobs >= $queueWorkerOptions->maxJobs) {
            return true;
        }

        return $queueWorkerOptions->maxTime > 0 && (time() - $startedAt) >= $queueWorkerOptions->maxTime;
    }

    /**
     * Sleep while dispatching PCNTL signals to allow graceful shutdown.
     */
    private function sleepWithSignalDispatch(int $seconds): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            for ($i = 0; $i < $seconds; $i++) {
                pcntl_signal_dispatch();
                if (! $this->running) {
                    break;
                }

                sleep(1);
            }
        } else {
            sleep($seconds);
        }
    }
}
