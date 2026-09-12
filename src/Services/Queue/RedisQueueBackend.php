<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Services\Settings;
use App\Services\TelegramService;
use JsonException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class RedisQueueBackend implements LeasedQueueBackendInterface
{
    public function __construct(
        private QueueRedisConnection $queueRedisConnection,
        private Settings $settings,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function dispatch(string $jobClass, array $payload, string $queue): string
    {
        $jobId = Uuid::uuid7()->toString();
        $deduplicationKey = '';
        if ($jobClass === TelegramService::class && isset($payload['update_json'])) {
            $update = $this->deserialize((string) $payload['update_json']);
            if (isset($update['update_id']) && is_int($update['update_id'])) {
                $bot = hash('sha256', (string) $this->settings->get('telegram.bot_token'));
                $deduplicationKey = $this->prefix() . 'telegram:update:' . $bot . ':' . $update['update_id'];
            }
        }

        return (string) $this->queueRedisConnection->evaluate(QueueScripts::DISPATCH, [
            $this->queueKey($queue), $this->jobKey($jobId),
            $jobId, $queue, $jobClass, $this->serialize($payload), $deduplicationKey,
        ], 2);
    }

    public function reserveNextAvailable(string $queueName, int $timeout = 5): ?QueueMessage
    {
        $deadline = microtime(true) + max(0, $timeout);
        do {
            $result = $this->transition($queueName, 'reserve', '', Uuid::uuid7()->toString());
            if (is_array($result) && $result !== []) {
                $data = [];
                $counter = count($result);
                for ($index = 0; $index < $counter; $index += 2) {
                    $data[$result[$index]] = $result[$index + 1];
                }

                // Malformed payloads stay leased and eventually reach dead-letter too.
                return new QueueMessage(
                    (string) $data['id'],
                    (string) $data['job_class'],
                    $this->deserialize((string) $data['payload']),
                    $queueName,
                    $data,
                );
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep(100_000);
        } while (true);
    }

    public function delete(QueueMessage $queueMessage): void
    {
        if ($this->transitionMessage($queueMessage, 'ack') !== 1) {
            throw new RuntimeException('Cannot acknowledge an expired or superseded queue reservation');
        }
    }

    public function release(QueueMessage $queueMessage): void
    {
        $this->transitionMessage($queueMessage, 'release');
    }

    public function renew(QueueMessage $queueMessage): bool
    {
        return $this->transitionMessage($queueMessage, 'renew') === 1;
    }

    public function withLease(QueueMessage $queueMessage, callable $operation): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_getppid')) {
            throw new RuntimeException('Queue lease heartbeat requires pcntl and posix');
        }

        $parent = getmypid();
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Unable to start queue lease heartbeat');
        }

        if ($pid === 0) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_async_signals(true);
            try {
                while (posix_getppid() === $parent) {
                    usleep(max(100_000, (int) ($this->option('leaseSeconds', 300) * 1_000_000 / 3)));
                    if (posix_getppid() !== $parent || ! $this->renew($queueMessage)) {
                        break;
                    }
                }
            } catch (\Throwable) {
                // Redis recovers the reservation when renewal is no longer possible.
            }

            // Never run shutdown hooks/destructors inherited from the worker's DB connections.
            posix_kill(getmypid(), SIGKILL);
            exit(1);
        }

        try {
            if (! $this->renew($queueMessage)) {
                throw new RuntimeException('Queue reservation was lost before execution');
            }

            $operation();
            if (! $this->renew($queueMessage)) {
                throw new RuntimeException('Queue reservation was lost during execution');
            }
        } finally {
            // SIGTERM may still have the worker's handler if the child has not been scheduled yet.
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
    }

    /** @param array<string, mixed> $payload */
    public function serialize(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Unable to encode queue payload', 0, $jsonException);
        }
    }

    /** @return array<string, mixed> */
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

    private function transitionMessage(QueueMessage $queueMessage, string $action): mixed
    {
        return $this->transition(
            $queueMessage->queueName, $action, $queueMessage->id, (string) ($queueMessage->metadata['token'] ?? ''),
        );
    }

    private function transition(string $queue, string $action, string $id, string $token): mixed
    {
        $key = $this->queueKey($queue);
        return $this->queueRedisConnection->evaluate(QueueScripts::TRANSITION, [
            $key, $key . ':leased', $key . ':delayed', $key . ':dead',
            $action, $this->prefix() . 'queue:job:', $id, $token,
            $this->option('leaseSeconds', 300), $this->option('maxAttempts', 5),
            $this->option('retryDelaySeconds', 5), $this->option('maxRetryDelaySeconds', 300),
            $this->option('deduplicationSeconds', 604800),
        ], 4);
    }

    private function option(string $name, int $default): int
    {
        $options = $this->settings->get('queue');
        return max(1, (int) ($options[$name] ?? $default));
    }

    private function prefix(): string
    {
        return (string) $this->settings->get('redis.prefix');
    }

    private function queueKey(string $queue): string
    {
        return $this->prefix() . 'queue:' . $queue;
    }

    private function jobKey(string $id): string
    {
        return $this->prefix() . 'queue:job:' . $id;
    }
}
