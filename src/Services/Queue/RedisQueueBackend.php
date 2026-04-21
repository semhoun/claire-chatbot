<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Services\RedisClient;
use App\Services\Settings;
use JsonException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class RedisQueueBackend implements QueueBackendInterface
{
    protected bool $mustReconnect = false;

    public function __construct(
        private readonly RedisClient $redisClient,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(
        string $jobClass,
        array $payload,
        string $queue,
    ): string {
        if ($this->mustReconnect) {
            $this->redisClient->reconnect();
            $this->mustReconnect = false;
        }

        $jobId = Uuid::uuid7()->toString();
        $encodedPayload = $this->serialize($payload);

        $jobData = [
            'id' => $jobId,
            'queue_name' => $queue,
            'job_class' => $jobClass,
            'payload' => $encodedPayload,
        ];

        $this->assertRedisResult(
            $this->redisClient->hset($this->jobKey($jobId), $jobData, $this->settings->get('queue.expireAfter')),
            'Unable to persist Redis queue job payload'
        );
        $this->assertRedisResult(
            $this->redisClient->lpush($this->queueKey($queue), [$jobId]),
            'Unable to enqueue Redis queue job'
        );

        return $jobId;
    }

    public function reserveNextAvailable(
        string $queueName,
        int $timeout = 5,
    ): ?QueueMessage {
        if ($this->mustReconnect) {
            $this->redisClient->reconnect();
            $this->mustReconnect = false;
        }

        $queueKey = $this->queueKey($queueName);

        try {
            $result = $this->redisClient->brpop([$queueKey], $timeout);

            if ($result === null) {
                return null;
            }
        }
        catch (\Throwable $throwable) {
            $this->mustReconnect = true;
            throw $throwable;
        }

        $jobId = $result[1];

        return $this->hydrateQueueMessage($jobId);
    }

    public function delete(QueueMessage $queueMessage): void
    {
        $this->redisClient->del($this->jobKey($queueMessage->id));
    }

    public function release(QueueMessage $queueMessage): void
    {
        $this->assertRedisResult(
            $this->redisClient->lpush($this->queueKey($queueMessage->queueName), [$queueMessage->id]),
            'Unable to release job back to queue'
        );
    }

    private function hydrateQueueMessage(string $jobId): QueueMessage
    {
        $jobData = $this->getJobData($jobId);
        return new QueueMessage(
            id: $jobId,
            jobClass: (string) ($jobData['job_class'] ?? ''),
            payload: $this->deserialize((string) ($jobData['payload'] ?? '[]')),
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
            $this->mustReconnect = true;
            throw new RuntimeException($message);
        }
    }

    public function serialize(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Unable to encode queue payload', 0, $jsonException);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function deserialize(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Unable to decode queue payload', 0, $jsonException);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Queue payload must decode to an array');
        }

        return $decoded;
    }
}
