<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Job\Telegram\StartThreadJob;
use App\Services\ChatGenerationBusyException;
use App\Services\ChatThreadLock;
use App\Services\Settings;
use App\Services\TelegramJournal;
use App\Services\TelegramService;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/** SQL is authoritative; Redis is a replaceable transport for these two job classes only. */
final readonly class SqlQueueOutbox
{
    public function __construct(private Connection $connection, private Settings $settings)
    {
    }

    public function supports(string $jobClass): bool
    {
        return in_array($jobClass, [TelegramService::class, StartThreadJob::class], true);
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $jobClass, array $payload, string $queue): string
    {
        $this->assertAutocommit();
        if (! $this->supports($jobClass)) {
            throw new \InvalidArgumentException('Not a Telegram outbox job');
        }

        $id = Uuid::uuid7()->toString();
        if ($jobClass === StartThreadJob::class) {
            $payload['generationId'] ??= $id;
        }

        $event = $this->eventKey($jobClass, $payload);
        $lock = $this->lock('event:' . $event);
        try {
            $existing = $this->connection->fetchOne('SELECT id FROM queue_outbox WHERE event_key = ?', [$event]);
            if ($existing !== false) {
                return (string) $existing;
            }

            $now = $this->now();
            $this->connection->insert('queue_outbox', [
                'id' => $id, 'event_key' => $event, 'job_class' => $jobClass, 'queue_name' => $queue,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'status' => 'pending',
                'attempts' => 0, 'available_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            return $id;
        } finally {
            $lock->release();
        }
    }

    /** @return array{selected:int, published:int, failed:int, skipped:int} */
    public function publishPending(?string $queue, int $limit, callable $publisher): array
    {
        $this->assertAutocommit();
        $limit = max(0, min(1000, $limit));
        $now = $this->now();
        $params = [$now, $now];
        $sql = "SELECT id FROM queue_outbox WHERE available_at <= ? AND (status IN ('pending', 'published')
            OR (status = 'processing' AND lease_until <= ?))";
        if ($queue !== null) {
            $sql .= ' AND queue_name = ?';
            $params[] = $queue;
        }

        $ids = $this->connection->fetchFirstColumn($sql . ' ORDER BY available_at, id LIMIT ' . $limit, $params);
        $result = ['selected' => count($ids), 'published' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($ids as $id) {
            $result[$this->publish((string) $id, $publisher)]++;
        }

        return $result;
    }

    /** Publish first, then acknowledge in SQL. A lost SQL acknowledgement is harmless. */
    public function publish(string $id, callable $publisher): string
    {
        $this->assertAutocommit();
        $now = $this->now();
        try {
            $lock = $this->lock($id);
        } catch (ChatGenerationBusyException) {
            // Scheduling only: do not acquire or overwrite the active executor's fence.
            $this->connection->executeStatement("UPDATE queue_outbox SET available_at = ?, updated_at = ?
                WHERE id = ? AND status IN ('pending', 'published', 'processing') AND available_at <= ?", [
                $now + min($this->option('retryDelaySeconds', 5), $this->option('maxRetryDelaySeconds', 300)),
                $now, $id, $now,
            ]);
            return 'skipped';
        }

        try {
            $row = $this->load($id);
            if ($row === false || in_array($row['status'], ['completed', 'dead'], true)
                || (int) $row['available_at'] > $now) {
                return 'skipped';
            }

            if ($this->completeDelivered($row)) {
                return 'skipped';
            }

            if ((int) $row['attempts'] >= $this->option('maxAttempts', 5)) {
                $this->dead($id, 'Telegram attempt budget exhausted');
                return 'skipped';
            }

            if ($row['status'] === 'processing') {
                if ((int) $row['lease_until'] > $now) {
                    return 'skipped';
                }

                if (! $this->canResume($row)) {
                    $this->dead($id, 'Ambiguous interrupted Telegram operation');
                    return 'skipped';
                }
            }

            try {
                $publisher($id, $row['job_class'], json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR),
                    $row['queue_name']);
            } catch (Throwable) {
                $now = $this->now();
                // Never store transport exception text: it can contain credentials or payloads.
                $this->connection->update('queue_outbox', [
                    'status' => $row['status'] === 'processing' ? 'processing' : 'pending',
                    'available_at' => $now + min($this->option('retryDelaySeconds', 5),
                        $this->option('maxRetryDelaySeconds', 300)),
                    'updated_at' => $now, 'last_error' => 'Redis publication failed',
                ], ['id' => $id]);
                return 'failed';
            }

            $now = $this->now();
            $this->connection->update('queue_outbox', [
                'status' => 'published', 'published_at' => $now, 'updated_at' => $now,
                'available_at' => $now + $this->option('outboxRepublishSeconds', 60),
                'lease_until' => null, 'lease_token' => null, 'last_error' => null,
            ], ['id' => $id]);
            return 'published';
        } finally {
            $lock->release();
        }
    }

    public function execute(QueueMessage $queueMessage, callable $operation): void
    {
        if (! $this->supports($queueMessage->jobClass)) {
            $operation();
            return;
        }

        if (($queueMessage->metadata['outbox_id'] ?? null) !== $queueMessage->id
            || ! is_string($queueMessage->metadata['token'] ?? null) || $queueMessage->metadata['token'] === '') {
            throw new NonRetryableJobException('Telegram job requires the SQL outbox protocol');
        }

        $this->assertAutocommit();
        $now = $this->now();
        $id = $queueMessage->id;
        $token = $queueMessage->metadata['token'];
        $lock = $this->lock($id);
        try {
            $row = $this->load($id);
            if ($row === false || $row['job_class'] !== $queueMessage->jobClass
                || RedisQueueBackend::OUTBOX_QUEUE_PREFIX . $row['queue_name'] !== $queueMessage->queueName) {
                throw new NonRetryableJobException('Missing or mismatched Telegram outbox receipt');
            }

            if ($row['status'] === 'completed') {
                return;
            }

            if ($row['status'] === 'dead') {
                throw new NonRetryableJobException('Telegram receipt is dead');
            }

            if ($row['payload'] === null
                || json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR) !== $queueMessage->payload
                || $row['event_key'] !== $this->eventKey($queueMessage->jobClass, $queueMessage->payload)) {
                throw new NonRetryableJobException('Telegram job does not match its SQL intent');
            }

            if ($this->completeDelivered($row)) {
                return;
            }

            if ((int) $row['attempts'] >= $this->option('maxAttempts', 5)) {
                $this->dead($id, 'Telegram attempt budget exhausted');
                throw new NonRetryableJobException('Telegram attempt budget exhausted');
            }

            if ($row['status'] === 'processing') {
                if ((int) $row['lease_until'] > $now) {
                    throw new ChatGenerationBusyException('Telegram receipt is leased');
                }

                if (! $this->canResume($row)) {
                    $this->dead($id, 'Ambiguous interrupted Telegram operation');
                    throw new NonRetryableJobException('Ambiguous interrupted Telegram operation');
                }
            } elseif ((int) $row['available_at'] > $now && $row['status'] === 'pending') {
                throw new ChatGenerationBusyException('Telegram receipt is delayed');
            }

            $this->connection->update('queue_outbox', [
                'status' => 'processing', 'attempts' => (int) $row['attempts'] + 1,
                'lease_token' => $token, 'lease_until' => $now + $this->option('outboxLeaseSeconds', 900),
                'updated_at' => $now,
            ], ['id' => $id]);
            try {
                $operation();
            } catch (Throwable $error) {
                $this->finishFailure($id, $token, $error instanceof ChatGenerationBusyException ? 'defer'
                    : ($error instanceof NonRetryableJobException ? 'fail' : 'release'));
                throw $error;
            }

            $this->assertAutocommit();
            $now = $this->now();
            $changed = $this->connection->executeStatement("UPDATE queue_outbox SET status = 'completed',
                payload = NULL, completed_at = ?, updated_at = ?, lease_until = NULL,
                lease_token = NULL, last_error = NULL
                WHERE id = ? AND status = 'processing' AND lease_token = ?", [$now, $now, $id, $token]);
            if ($changed !== 1) {
                throw new RuntimeException('Outbox execution fence lost');
            }
        } finally {
            $lock->release();
        }
    }

    public function transition(QueueMessage $queueMessage, string $action): void
    {
        if (! $this->supports($queueMessage->jobClass) || ($queueMessage->metadata['outbox_id'] ?? null) !== $queueMessage->id
            || ! is_string($queueMessage->metadata['token'] ?? null) || $queueMessage->metadata['token'] === '') {
            return;
        }

        $this->assertAutocommit();
        try {
            $lock = $this->lock($queueMessage->id);
        } catch (ChatGenerationBusyException) {
            return;
        }

        try {
            $this->finishFailure($queueMessage->id, $queueMessage->metadata['token'], $action);
        } finally {
            $lock->release();
        }
    }

    private function finishFailure(string $id, string $token, string $action): void
    {
        $this->assertAutocommit();
        $row = $this->load($id);
        if ($row === false || $row['status'] !== 'processing' || $row['lease_token'] !== $token) {
            return;
        }

        if ($this->completeDelivered($row)) {
            return;
        }

        $attempts = max(0, (int) $row['attempts'] - ($action === 'defer' ? 1 : 0));
        $dead = $action === 'fail' || ($action !== 'defer'
            && ($attempts >= $this->option('maxAttempts', 5) || ! $this->canResume($row)));
        $delay = min($this->option('maxRetryDelaySeconds', 300),
            $this->option('retryDelaySeconds', 5) * (2 ** min(20, max(0, $attempts - 1))));
        $now = $this->now();
        $this->connection->update('queue_outbox', [
            'status' => $dead ? 'dead' : 'pending', 'attempts' => $attempts,
            'available_at' => $now + (int) $delay, 'lease_until' => null, 'lease_token' => null,
            'updated_at' => $now, 'last_error' => $dead ? 'Terminal or ambiguous execution' : 'Execution deferred',
        ], ['id' => $id]);
    }

    /** A logger/observer failure after delivery must not make the handler replayable.
     * Callers hold the receipt's advisory lock; SQL delivery is the authoritative fence.
     * @param array<string, mixed> $row
     */
    private function completeDelivered(array $row): bool
    {
        $this->assertAutocommit();
        if (! (bool) $this->connection->fetchOne('SELECT delivered FROM telegram_generation WHERE id = ?',
            [$row['event_key']])) {
            return false;
        }

        $now = $this->now();
        $changed = $this->connection->update('queue_outbox', [
            'status' => 'completed', 'payload' => null, 'completed_at' => $now, 'updated_at' => $now,
            'lease_until' => null, 'lease_token' => null, 'last_error' => null,
        ], ['id' => $row['id'], 'status' => $row['status'], 'lease_token' => $row['lease_token']]);
        if ($changed !== 1) {
            throw new RuntimeException('Outbox delivery fence lost');
        }

        return true;
    }

    /** @param array<string, mixed> $row */
    private function canResume(array $row): bool
    {
        $journal = $this->connection->fetchAssociative(
            'SELECT attempted, delivered, response FROM telegram_generation WHERE id = ?',
            [$row['event_key']]);
        return $journal !== false && ((bool) $journal['delivered']
            || $journal['response'] !== null || ! (bool) $journal['attempted']);
    }

    /** @param array<string, mixed> $payload */
    private function eventKey(string $jobClass, array $payload): string
    {
        if ($jobClass === TelegramService::class) {
            $update = json_decode((string) ($payload['update_json'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
            if (! is_int($update['update_id'] ?? null)) {
                throw new \InvalidArgumentException('Stable Telegram update ID is required');
            }

            $event = 'update:' . $update['update_id'];
        } else {
            if (! is_string($payload['generationId'] ?? null) || $payload['generationId'] === '') {
                throw new \InvalidArgumentException('Stable Telegram generation ID is required');
            }

            $event = 'job:' . $payload['generationId'];
        }

        return hash('sha256', json_encode([
            explode(':', (string) $this->settings->get('telegram.bot_token'), 2)[0], $event,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|false */
    private function load(string $id): array|false
    {
        return $this->connection->fetchAssociative('SELECT * FROM queue_outbox WHERE id = ?', [$id]);
    }

    private function dead(string $id, string $reason): void
    {
        $this->connection->update('queue_outbox', [
            'status' => 'dead', 'lease_until' => null, 'lease_token' => null,
            'updated_at' => $this->now(), 'last_error' => $reason,
        ], ['id' => $id]);
    }

    private function lock(string $id): ChatThreadLock
    {
        return new ChatThreadLock($this->connection->getNativeConnection(), 'outbox', $id);
    }

    private function now(): int
    {
        return new TelegramJournal($this->connection)->now();
    }

    private function assertAutocommit(): void
    {
        if ($this->connection->isTransactionActive() || $this->connection->getNativeConnection()->inTransaction()) {
            throw new RuntimeException('Outbox requires an autocommit primary SQL connection');
        }
    }

    private function option(string $name, int $default): int
    {
        $options = $this->settings->get('queue');
        return max(1, min(86400, (int) ($options[$name] ?? $default)));
    }
}
