<?php

declare(strict_types=1);

namespace App\Services\Queue;

final class SqlOutboxQueueBackend implements LeasedQueueBackendInterface
{
    private bool $outboxFirst = true;

    public function __construct(
        private readonly RedisQueueBackend $redisQueueBackend,
        private readonly SqlQueueOutbox $sqlQueueOutbox,
    ) {
    }

    public function dispatch(string $jobClass, array $payload, string $queue): string
    {
        if (! $this->sqlQueueOutbox->supports($jobClass)) {
            return $this->redisQueueBackend->dispatch($jobClass, $payload, $queue);
        }

        $id = $this->sqlQueueOutbox->enqueue($jobClass, $payload, $queue);
        $this->sqlQueueOutbox->publish($id, $this->redisQueueBackend->dispatchWithId(...));
        return $id;
    }

    /** @return array{selected:int, published:int, failed:int, skipped:int} */
    public function publishPending(?string $queue = null, int $limit = 100): array
    {
        return $this->sqlQueueOutbox->publishPending($queue, $limit, $this->redisQueueBackend->dispatchWithId(...));
    }

    public function reserveNextAvailable(string $queueName, int $timeout = 5): ?QueueMessage
    {
        $deadline = microtime(true) + max(0, $timeout);
        $this->publishPending($queueName, 25);
        $transport = RedisQueueBackend::OUTBOX_QUEUE_PREFIX . $queueName;
        do {
            // Neither transport may block the other; alternate priority after each reservation.
            foreach ($this->outboxFirst ? [$transport, $queueName] : [$queueName, $transport] as $queue) {
                $job = $this->redisQueueBackend->reserveNextAvailable($queue, 0);
                if ($job !== null) {
                    $this->outboxFirst = $queue === $queueName;
                    return $job;
                }
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }

            usleep((int) min(100_000, $remaining * 1_000_000));
        } while (true);
    }

    public function withLease(QueueMessage $queueMessage, callable $operation): void
    {
        $this->redisQueueBackend->withLease($queueMessage, fn () => $this->sqlQueueOutbox->execute($queueMessage, $operation));
    }

    public function delete(QueueMessage $queueMessage): void
    {
        $this->redisQueueBackend->delete($queueMessage);
    }

    public function release(QueueMessage $queueMessage): void
    {
        $this->sqlQueueOutbox->transition($queueMessage, 'release');
        $this->redisQueueBackend->release($queueMessage);
    }

    public function fail(QueueMessage $queueMessage): void
    {
        $this->sqlQueueOutbox->transition($queueMessage, 'fail');
        $this->redisQueueBackend->fail($queueMessage);
    }

    public function defer(QueueMessage $queueMessage): void
    {
        $this->sqlQueueOutbox->transition($queueMessage, 'defer');
        $this->redisQueueBackend->defer($queueMessage);
    }
}
