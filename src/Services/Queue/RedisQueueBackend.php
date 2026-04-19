<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Services\RedisClientInterface;
use App\Services\Settings;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class RedisQueueBackend implements QueueBackendInterface
{
    public function __construct(
        private RedisClientInterface $redisClient,
        private QueueSerializer $queueSerializer,
        private Settings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(
        string $jobClass,
        array $payload = [],
        string $queue = 'default',
    ): string {
        $queueName = $this->normalizeQueueName($queue);
        $jobId = Uuid::uuid7()->toString();
        $encodedPayload = $this->queueSerializer->encode($payload);

        $jobData = [
            'id' => $jobId,
            'queue_name' => $queueName,
            'job_class' => $jobClass,
            'payload' => $encodedPayload,
        ];

        $this->assertRedisResult(
            $this->redisClient->hset($this->jobKey($jobId), $jobData),
            'Unable to persist Redis queue job payload'
        );
        $this->assertRedisResult(
            $this->redisClient->lpush($this->queueKey($queueName), [$jobId]),
            'Unable to enqueue Redis queue job'
        );

        return $jobId;
    }

    public function reserveNextAvailable(
        string $queueName,
        int $timeout = 5,
    ): ?QueueMessage {
        $queueName = $this->normalizeQueueName($queueName);
        $queueKey = $this->queueKey($queueName);

        $result = $this->redisClient->brpop([$queueKey], $timeout);

        if ($result === null) {
            return null;
        }

        $jobId = $result[1];

        return $this->hydrateQueueMessage($jobId);
    }

    public function delete(QueueMessage $queueMessage): void
    {
        $this->redisClient->del($this->jobKey($queueMessage->id));
    }

    private function hydrateQueueMessage(string $jobId): QueueMessage
    {
        $jobData = $this->getJobData($jobId);

        return new QueueMessage(
            id: $jobId,
            jobClass: (string) ($jobData['job_class'] ?? ''),
            payload: $this->queueSerializer->decode((string) ($jobData['payload'] ?? '[]')),
            queueName: (string) ($jobData['queue_name'] ?? $this->settings->get('queue.defaultQueue')),
            metadata: $jobData,
        );
    }

    /**
     * @return array<string, string>
     */
    private function getJobData(string $jobId): array
    {
        $jobData = $this->redisClient->hgetall($this->jobKey($jobId));
        if (! is_array($jobData) || $jobData === []) {
            throw new RuntimeException(sprintf('Redis queue job "%s" not found', $jobId));
        }

        return array_map(static fn (mixed $value): string => $value, $jobData);
    }

    private function normalizeQueueName(string $queueName): string
    {
        return $queueName === 'default'
            ? (string) $this->settings->get('queue.defaultQueue')
            : $queueName;
    }

    private function queueKey(string $queueName): string
    {
        return $this->settings->get('redis.prefix') . 'queue:' . $queueName;
    }

    private function jobKey(string $jobId): string
    {
        return $this->settings->get('redis.prefix') . 'queue:job:' . $jobId;
    }

    private function assertRedisResult(mixed $result, string $message): void
    {
        if ($result === false) {
            throw new RuntimeException($message);
        }
    }
}
