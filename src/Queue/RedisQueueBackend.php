<?php

declare(strict_types=1);

namespace App\Queue;

use App\Redis\RedisClientInterface;
use App\Services\Settings;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class RedisQueueBackend implements QueueBackendInterface
{
    public function __construct(
        private RedisClientInterface $redis,
        private QueueSerializer $serializer,
        private Settings $settings,
    ) {
    }

    public function dispatch(
        string $jobClass,
        array $payload = [],
        string $queue = 'default',
        ?\DateTimeImmutable $availableAt = null,
        ?int $maxAttempts = null,
    ): string {
        $queueName = $this->normalizeQueueName($queue);
        $jobId = Uuid::uuid7()->toString();
        $availableTimestamp = ($availableAt ?? new \DateTimeImmutable('now'))->getTimestamp();
        $encodedPayload = $this->serializer->encode($payload);

        $jobData = [
            'id' => $jobId,
            'queue_name' => $queueName,
            'job_class' => $jobClass,
            'payload' => $encodedPayload,
            'available_at' => (string) $availableTimestamp,
        ];

        $this->assertRedisResult(
            $this->redis->hset($this->jobKey($jobId), $jobData),
            'Unable to persist Redis queue job payload'
        );
        $this->assertRedisResult(
            $this->redis->zadd($this->pendingKey($queueName), [$jobId => $availableTimestamp]),
            'Unable to enqueue Redis queue job'
        );

        return $jobId;
    }

    public function reserveNextAvailable(
        string $queueName,
    ): ?QueueMessage {
        $queueName = $this->normalizeQueueName($queueName);
        $now = time();

        $jobId = $this->popPendingJob($queueName, $now);
        if (! is_string($jobId) || $jobId === '') {
            return null;
        }

        return $this->hydrateQueueMessage($jobId);
    }

    public function delete(QueueMessage $message): void
    {
    }

    private function popPendingJob(
        string $queueName,
        int $now,
    ): ?string {
        $script = <<<'LUA'
local pendingKey = KEYS[1]
local now = tonumber(ARGV[1])

local jobs = redis.call('ZRANGEBYSCORE', pendingKey, '-inf', now, 'LIMIT', 0, 1)
if #jobs == 0 then
    return false
end

local jobId = jobs[1]
redis.call('ZREM', pendingKey, jobId)
return jobId
LUA;

        $result = $this->redis->eval($script, [
            $this->pendingKey($queueName),
            (string) $now,
        ], 1);

        return is_string($result) && $result !== '' ? $result : null;
    }

    private function hydrateQueueMessage(string $jobId): QueueMessage
    {
        $jobData = $this->getJobData($jobId);

        return new QueueMessage(
            id: $jobId,
            jobClass: (string) ($jobData['job_class'] ?? ''),
            payload: $this->serializer->decode((string) ($jobData['payload'] ?? '[]')),
            queueName: (string) ($jobData['queue_name'] ?? $this->settings->get('queue.defaultQueue')),
            metadata: $jobData,
        );
    }

    /**
     * @return array<string, string>
     */
    private function getJobData(string $jobId): array
    {
        $jobData = $this->redis->hgetall($this->jobKey($jobId));
        if (! is_array($jobData) || $jobData === []) {
            throw new RuntimeException(sprintf('Redis queue job "%s" not found', $jobId));
        }

        return array_map(static fn (mixed $value): string => (string) $value, $jobData);
    }

    private function normalizeQueueName(string $queueName): string
    {
        return $queueName === 'default'
            ? (string) $this->settings->get('queue.defaultQueue')
            : $queueName;
    }

    private function pendingKey(string $queueName): string
    {
        return $this->settings->get('redis.prefix') . 'queue:' . $queueName . ':pending';
    }

    private function jobsPrefix(): string
    {
        return $this->settings->get('redis.prefix') . 'queue:job:';
    }

    private function jobKey(string $jobId): string
    {
        return $this->jobsPrefix() . $jobId;
    }

    private function assertRedisResult(mixed $result, string $message): void
    {
        if ($result === false) {
            throw new RuntimeException($message);
        }
    }
}
