<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use RuntimeException;

/** SQL is authoritative. Never hold a transaction here across agent, tool or network execution. */
final readonly class ChatStopRequests
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Register BEFORE enqueue, not just at worker entry. Never reuse a generation identity.
     * Duplicate registration cannot clear a stop request or reopen a terminal generation.
     *
     * @return array{status: string, stopRequested: bool, created: bool}
     */
    public function register(string $userId, string $threadId, string $channel, string $generationId): array
    {
        $id = $this->identity($userId, $threadId, $channel, $generationId);
        $created = true;
        try {
            // A savepoint also keeps a duplicate insert from aborting a caller's PostgreSQL transaction.
            $this->connection->transactional(function () use ($id, $userId, $threadId, $channel, $generationId): void {
                $this->connection->insert('chat_stop_request', [
                    'id' => $id, 'user_id' => $userId, 'thread_id' => $threadId, 'channel' => $channel,
                    'generation_id' => $generationId, 'status' => 'accepted', 'stop_requested' => 0,
                    'created_at' => time(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The existing immutable identity, including a terminal result, wins.
            $created = false;
        }

        $record = $this->read($id, $this->connection->isTransactionActive())
            ?? throw new RuntimeException('Accepted generation is unavailable');
        return $record + ['created' => $created];
    }

    /**
     * No conversation lock, Redis dependency, user content or inference. Unknown identities stay unknown.
     *
     * @return array{status: string, stopRequested: bool}|null
     */
    public function request(string $userId, string $threadId, string $channel, string $generationId): ?array
    {
        $id = $this->identity($userId, $threadId, $channel, $generationId);
        $this->requireIndependentTransaction();
        return $this->connection->transactional(function () use ($id): ?array {
            $this->connection->executeStatement(
                'UPDATE chat_stop_request SET stop_requested = 1, requested_at = ?'
                . " WHERE id = ? AND status = 'accepted' AND stop_requested = 0",
                [time(), $id],
            );
            return $this->read($id, true);
        });
    }

    /** Unknown identities fail closed: workers must register before testing the predicate. */
    public function isRequested(string $userId, string $threadId, string $channel, string $generationId): bool
    {
        $record = $this->read($this->identity($userId, $threadId, $channel, $generationId));
        if ($record === null) {
            throw new RuntimeException('Accepted generation is unavailable');
        }
        return $record['stopRequested'];
    }

    /**
     * Call BEFORE history/journal finalization, inside their SAME connection/transaction.
     * Persist the returned status with the history, or roll back the whole transaction.
     * won=false forbids a second history mutation. Publish SSE only AFTER the caller commits.
     * A rollback remains a rollback even if stopped: crash recovery must not claim preserved tokens.
     *
     * @return array{status: string, stopRequested: bool, won: bool}
     */
    public function arbitrateTerminal(
        string $userId,
        string $threadId,
        string $channel,
        string $generationId,
        string $proposedStatus,
    ): array {
        $id = $this->identity($userId, $threadId, $channel, $generationId);
        if (! in_array($proposedStatus, ['succeeded', 'stopped', 'rolled_back'], true)) {
            throw new InvalidArgumentException('Invalid generation terminal status');
        }
        if (! $this->connection->isTransactionActive()) {
            throw new RuntimeException('Stop arbitration requires the history transaction');
        }
        $won = $this->connection->executeStatement(
            'UPDATE chat_stop_request SET status = CASE'
            . " WHEN stop_requested = 1 AND ? = 'succeeded' THEN 'stopped' ELSE ? END, completed_at = ?"
            . " WHERE id = ? AND status = 'accepted'",
            [$proposedStatus, $proposedStatus, time(), $id],
        ) === 1;
        $record = $this->read($id, true);
        if ($record === null) {
            throw new RuntimeException('Accepted generation is unavailable');
        }
        return $record + ['won' => $won];
    }

    /**
     * Caller chooses a cutoff OLDER than all queue/retry/dedup horizons. Never deletes pending work.
     * After expiry, old workers must consult journal/dedup before registration, not recreate an attempt.
     */
    public function cleanup(int $completedBefore, int $limit = 100): int
    {
        if ($completedBefore < 0 || $completedBefore >= time() || $limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Invalid stop request retention bounds');
        }
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM chat_stop_request WHERE completed_at < ? ORDER BY completed_at, id LIMIT ' . $limit,
            [$completedBefore],
        );
        $deleted = 0;
        foreach ($ids as $id) {
            $deleted += $this->connection->executeStatement(
                "DELETE FROM chat_stop_request WHERE id = ? AND status <> 'accepted' AND completed_at < ?",
                [$id, $completedBefore],
            );
        }
        return $deleted;
    }

    private function identity(string $userId, string $threadId, string $channel, string $generationId): string
    {
        if ($userId === '' || strlen($userId) > 255 || ! in_array($channel, ['web', 'telegram'], true)) {
            throw new InvalidArgumentException('Invalid stop request owner or channel');
        }
        if ($threadId === '' || strlen($threadId) > 128 || $generationId === '' || strlen($generationId) > 128) {
            throw new InvalidArgumentException('Invalid stop request identity');
        }
        // Exact byte identity even on databases with case-insensitive collations.
        return hash('sha256', json_encode([$userId, $threadId, $channel, $generationId], JSON_THROW_ON_ERROR));
    }

    /** @return array{status: string, stopRequested: bool}|null */
    private function read(string $id, bool $lock = false): ?array
    {
        // A current read avoids stale repeatable-read snapshots after a competing terminal CAS.
        $suffix = $lock && ! $this->connection->getDatabasePlatform() instanceof SQLitePlatform
            ? ' FOR UPDATE' : '';
        $row = $this->connection->fetchAssociative(
            'SELECT status, stop_requested FROM chat_stop_request WHERE id = ?' . $suffix,
            [$id],
        );
        return $row === false ? null : ['status' => $row['status'], 'stopRequested' => (bool) $row['stop_requested']];
    }

    private function requireIndependentTransaction(): void
    {
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            throw new RuntimeException('Stop requests require an independent short commit');
        }
    }
}
