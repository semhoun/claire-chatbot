<?php

declare(strict_types=1);

namespace App\Queue;

use Psr\Log\LoggerInterface as Logger;
use Symfony\Component\Console\Output\OutputInterface;

final class QueueWorker
{
    private bool $running = true;

    public function __construct(
        private readonly QueueBackendInterface $queueBackend,
        private readonly QueueJobFactory $queueJobFactory,
        private readonly Logger $logger,
    ) {
    }

    public function requestStop(): void
    {
        $this->running = false;
    }

    public function run(
        QueueWorkerOptions $options,
        string $workerId,
        OutputInterface $output,
    ): int {
        $startedAt = time();
        $processedJobs = 0;

        while ($this->running) {
            if ($this->hasReachedRuntimeLimit($options, $startedAt, $processedJobs)) {
                break;
            }

            $job = $this->queueBackend->reserveNextAvailable(
                $options->queueName,
            );

            if (! $job instanceof QueueMessage) {
                if ($options->once) {
                    break;
                }

                sleep($options->sleep);

                continue;
            }

            $processedJobs++;
            $this->processJob($job, $output);

            if ($options->once) {
                break;
            }
        }

        return $processedJobs;
    }

    private function processJob(QueueMessage $job, OutputInterface $output): void
    {
        $queueDoer = $this->queueJobFactory->createQueueDoer($job);
        $queueDoer->handle($job->payload);
        $this->queueBackend->delete($job);

        $this->logger->info('Queue job processed', [
            'job_id' => $job->id,
            'job_class' => $job->jobClass,
        ]);
        $output->writeln(sprintf('<info>Processed queue job %s</info>', $job->id));
    }

    private function hasReachedRuntimeLimit(
        QueueWorkerOptions $options,
        int $startedAt,
        int $processedJobs,
    ): bool {
        if ($options->maxJobs > 0 && $processedJobs >= $options->maxJobs) {
            return true;
        }

        return $options->maxTime > 0 && (time() - $startedAt) >= $options->maxTime;
    }

}
