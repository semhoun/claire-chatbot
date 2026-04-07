<?php

declare(strict_types=1);

namespace App\Queue;

use App\Services\RedisClientInterface;
use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Output\OutputInterface;

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

        while ($this->running) {
            if ($this->hasReachedRuntimeLimit($queueWorkerOptions, $startedAt, $processedJobs)) {
                break;
            }

            if (! $this->redisClient->ping()) {
                $this->logger->warning('Redis connection lost, attempting to reconnect', [
                    'worker_id' => $workerId,
                ]);
                $output->writeln('<warning>Redis connection lost, attempting to reconnect...</warning>');

                if (! $this->redisClient->reconnect()) {
                    $this->logger->error('Failed to reconnect to Redis', [
                        'worker_id' => $workerId,
                    ]);
                    $output->writeln('<error>Failed to reconnect to Redis, stopping worker</error>');

                    break;
                }

                $this->logger->info('Successfully reconnected to Redis', [
                    'worker_id' => $workerId,
                ]);
                $output->writeln('<info>Successfully reconnected to Redis</info>');
            }

            $job = $this->queueBackend->reserveNextAvailable(
                $queueWorkerOptions->queueName,
            );

            if (! $job instanceof QueueMessage) {
                if ($queueWorkerOptions->once) {
                    break;
                }

                sleep($queueWorkerOptions->sleep);

                continue;
            }

            $processedJobs++;
            $this->processJob($job, $output);

            if ($queueWorkerOptions->once) {
                break;
            }
        }

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
}
