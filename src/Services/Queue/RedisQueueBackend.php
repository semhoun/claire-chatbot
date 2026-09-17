<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Brain\ChatHistory\UserChatHistory;
use App\Job\Web\NewMessageJob;
use App\Job\Web\StartThreadJob;
use App\Services\Auth;
use App\Services\ChatGenerationBusyException;
use App\Services\ChatThreadLock;
use App\Services\Settings;
use App\Services\TelegramService;
use Doctrine\DBAL\Connection;
use JsonException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class RedisQueueBackend implements LeasedQueueBackendInterface
{
    public function __construct(
        private QueueRedisConnection $queueRedisConnection,
        private Settings $settings,
        private Connection $connection,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function dispatch(string $jobClass, array $payload, string $queue): string
    {
        $jobId = Uuid::uuid7()->toString();
        $stateKey = '';
        $messageId = '';
        $deduplicationKey = '';
        $lock = null;
        if (in_array($jobClass, [NewMessageJob::class, StartThreadJob::class], true)) {
            foreach (['threadId', 'sessionId'] as $field) {
                if (! is_string($payload[$field] ?? null) || trim($payload[$field]) === '') {
                    throw new \InvalidArgumentException('Missing chat ' . $field);
                }
            }

            if (! is_array($payload['session'] ?? null)) {
                throw new \InvalidArgumentException('Chat session is required');
            }

            $userId = $payload['session'][Auth::USERID] ?? null;
            if (! is_string($userId) || $userId === '') {
                throw new \InvalidArgumentException('Chat user identity is required');
            }

            $messageId = 'opening-' . $payload['threadId'];
            if ($jobClass === NewMessageJob::class) {
                $submissionId = $payload['submissionId'] ?? null;
                if (! is_string($submissionId)
                    || preg_match(UserChatHistory::MESSAGE_ID_PATTERN, $submissionId) !== 1) {
                    throw new \InvalidArgumentException('Stable submission ID is required');
                }
                $deduplicationKey = $this->prefix() . 'chat:submission:'
                    . hash('sha256', json_encode([$userId, $submissionId], JSON_THROW_ON_ERROR));
                $messageId = $payload['messageId'] ?? '';
                if (! is_string($messageId) || preg_match(UserChatHistory::MESSAGE_ID_PATTERN, $messageId) !== 1
                    || ! is_string($payload['message'] ?? null) || trim($payload['message']) === '') {
                    throw new \InvalidArgumentException('Stable message ID and message are required');
                }

                if (isset($payload['attachments'])) {
                    if (! is_array($payload['attachments'])) {
                        throw new \InvalidArgumentException('Chat attachments must be an array');
                    }

                    foreach (['uploadedFiles', 'fileIds'] as $field) {
                        if (isset($payload['attachments'][$field]) && ! is_array($payload['attachments'][$field])) {
                            throw new \InvalidArgumentException('Chat attachment groups must be arrays');
                        }
                    }
                }
            }

            $stateKey = $this->prefix() . 'chat:generation:'
                . hash('sha256', json_encode([$userId, $payload['threadId']], JSON_THROW_ON_ERROR));
            $lock = new ChatThreadLock($this->connection->getNativeConnection(), $userId, $payload['threadId']);
        }

        if ($jobClass === \App\Job\Telegram\StartThreadJob::class) {
            $payload['generationId'] ??= $jobId;
        }

        if ($jobClass === TelegramService::class && isset($payload['update_json'])) {
            $update = $this->deserialize((string) $payload['update_json']);
            if (isset($update['update_id']) && is_int($update['update_id'])) {
                $bot = hash('sha256', explode(':', (string) $this->settings->get('telegram.bot_token'), 2)[0]);
                $deduplicationKey = $this->prefix() . 'telegram:update:' . $bot . ':' . $update['update_id'];
            }
        }

        try {
            $result = $this->queueRedisConnection->evaluate(QueueScripts::DISPATCH, [
                $this->queueKey($queue), $this->jobKey($jobId),
                $jobId, $queue, $jobClass, $this->serialize($payload), $deduplicationKey, $stateKey, $messageId,
            ], 2);
            if ($result === 'CHAT_BUSY') {
                throw new ChatGenerationBusyException('Chat generation is busy or deleted');
            }

            return (string) $result;
        } finally {
            $lock?->release();
        }
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

    public function fail(QueueMessage $queueMessage): void
    {
        $this->transitionMessage($queueMessage, 'fail');
    }

    public function isFailed(QueueMessage $message): bool
    {
        return $this->queueRedisConnection->evaluate(
            "return redis.call('HGET', KEYS[1], 'state') or ''", [$this->jobKey($message->id)], 1,
        ) === 'dead';
    }

    /**
     * Revisits dead jobs, including reservations exhausted by lease recovery without a worker catch.
     *
     * @return list<QueueMessage>
     */
    public function failedMessages(string &$cursor): array
    {
        $page = $this->queueRedisConnection->evaluate(<<<'LUA'
            local page = redis.call('SCAN', ARGV[1], 'MATCH', ARGV[2], 'COUNT', 100)
            local jobs = {}
            for _, key in ipairs(page[2]) do
                if redis.call('TYPE', key).ok == 'hash' and redis.call('HGET', key, 'state') == 'dead' then
                    table.insert(jobs, redis.call('HGETALL', key))
                end
            end
            return {page[1], jobs}
            LUA, [$cursor, addcslashes($this->prefix(), '\\*?[]') . 'queue:job:*'], 0);
        $cursor = (string) $page[0];
        $messages = [];
        foreach ($page[1] as $values) {
            $data = [];
            $count = count($values);
            for ($i = 0; $i < $count; $i += 2) {
                $data[$values[$i]] = $values[$i + 1];
            }
            try {
                $messages[] = new QueueMessage($data['id'], $data['job_class'],
                    $this->deserialize($data['payload']), $data['queue_name'], $data);
            } catch (\Throwable) {
                // Malformed jobs cannot safely identify a conversation.
            }
        }
        return $messages;
    }

    public function defer(QueueMessage $queueMessage): void
    {
        $this->transitionMessage($queueMessage, 'defer');
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
            $queueMessage->queueName,
            $action,
            $queueMessage->id,
            (string) ($queueMessage->metadata['token'] ?? ''),
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
