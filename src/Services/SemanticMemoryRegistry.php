<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;
use RuntimeException;

/** SQL is the authority; vector files are disposable, untrusted projections. */
final readonly class SemanticMemoryRegistry
{
    public function __construct(
        private Connection $connection,
        private bool $enabled = false,
        private int $maxCharacters = 4000,
    ) {
        if ($maxCharacters < 64 || $maxCharacters > 16000) {
            throw new \InvalidArgumentException('Invalid semantic excerpt budget');
        }
    }

    /** @return array{enabled: bool, revision: int, indexVersion: int} */
    public function preference(string $userId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM semantic_memory_preference WHERE user_id = ?' . $this->currentRead(),
            [$userId],
        );
        if ($row !== false && $row['user_id'] !== $userId) {
            throw new RuntimeException('Semantic memory owner mismatch');
        }

        return ['enabled' => (bool) ($row['enabled'] ?? false),
            'revision' => (int) ($row['revision'] ?? 0), 'indexVersion' => (int) ($row['index_version'] ?? 1),
        ];
    }

    public function setEnabled(string $userId, bool $enabled): void
    {
        $this->connection->transactional(function () use ($userId, $enabled): void {
            $accountId = $this->connection->fetchOne(
                'SELECT id FROM account WHERE id = ?' . $this->currentRead(),
                [$userId],
            );
            if ($accountId !== $userId) {
                throw new RuntimeException('Semantic memory account mismatch');
            }

            $platform = strtolower($this->connection->getDatabasePlatform()::class);
            $sql = 'INSERT INTO semantic_memory_preference (user_id) VALUES (?)';
            $sql .= str_contains($platform, 'mysql') || str_contains($platform, 'mariadb')
                ? ' ON DUPLICATE KEY UPDATE user_id = user_id' : ' ON CONFLICT (user_id) DO NOTHING';
            $this->connection->executeStatement($sql, [$userId]);
            $this->lock($userId);
            if ($this->preference($userId)['enabled'] === $enabled) {
                return;
            }

            $this->connection->executeStatement(
                'UPDATE semantic_memory_preference SET enabled = ?, revision = revision + 1 WHERE user_id = ?',
                [(int) $enabled, $userId],
            );
        });
    }

    /** Call inside journal begin's atomic callback, never on an old completed turn. */
    public function captureTurn(string $userId, string $turnId): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->lock($userId);
        $pref = $this->preference($userId);
        if (! $pref['enabled']) {
            return;
        }

        $turn = $this->connection->fetchAssociative(
            "SELECT id, user_id, thread_id FROM chat_turn WHERE id = ? AND user_id = ? AND status = 'running'"
            . ' AND deleted_at IS NULL',
            [$turnId, $userId],
        );
        if ($turn === false || $turn['user_id'] !== $userId || $turn['id'] !== $turnId) {
            return;
        }

        $id = hash('sha256', json_encode([$userId, $turnId, $pref['revision']], JSON_THROW_ON_ERROR));
        if ($this->connection->fetchOne('SELECT id FROM semantic_memory_excerpt WHERE id = ?', [$id]) !== false) {
            return;
        }

        $this->connection->insert('semantic_memory_excerpt', [
            'id' => $id, 'user_id' => $userId, 'thread_id' => $turn['thread_id'], 'turn_id' => $turnId,
            'consent_revision' => $pref['revision'], 'index_version' => $pref['indexVersion'],
            'source_ids' => '[]', 'content' => '', 'status' => 'captured',
            'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    /** Only plain visible user/final-assistant text, not reasoning, attachments or tool messages. */
    public function recordSucceededTurn(
        string $userId,
        string $turnId,
        string $userMessageId,
        string $assistantMessageId,
        string $userText,
        string $assistantText,
    ): ?string {
        if (! $this->enabled) {
            return null;
        }

        $this->lock($userId);
        $pref = $this->preference($userId);
        if (! $pref['enabled'] || trim($userText) === '' || trim($assistantText) === ''
            || $userMessageId === '' || $assistantMessageId === '') {
            return null;
        }

        $turn = $this->connection->fetchAssociative(
            "SELECT id, user_id FROM chat_turn WHERE id = ? AND user_id = ? AND status = 'succeeded'"
            . ' AND deleted_at IS NULL',
            [$turnId, $userId],
        );
        if ($turn === false || $turn['user_id'] !== $userId || $turn['id'] !== $turnId) {
            return null;
        }

        $userText = mb_substr($userText, 0, intdiv($this->maxCharacters - 18, 2));
        $assistantText = mb_substr($assistantText, 0, $this->maxCharacters - 18 - mb_strlen($userText));
        $id = hash('sha256', json_encode([$userId, $turnId, $pref['revision']], JSON_THROW_ON_ERROR));
        $affected = $this->connection->executeStatement(
            "UPDATE semantic_memory_excerpt SET status = 'pending', content = ?, source_ids = ?, updated_at = ?"
            . ' WHERE user_id = ? AND turn_id = ? AND consent_revision = ? AND index_version = ?'
            . " AND status = 'captured' AND id = ?"
            . ' AND ' . $this->exactIdentity('user_id', '?')
            . ' AND ' . $this->exactIdentity('turn_id', '?'),
            ['User: ' . $userText . "\nAssistant: " . $assistantText,
                json_encode([$userMessageId, $assistantMessageId], JSON_THROW_ON_ERROR), time(),
                $userId, $turnId, $pref['revision'], $pref['indexVersion'], $id, $userId, $turnId,
            ],
        );
        return $affected === 1 ? $id : null;
    }

    /** Disable retains indexed data. Erasure invalidates every source and fences all in-flight work. */
    public function erase(string $userId): void
    {
        $this->connection->transactional(function () use ($userId): void {
            if (! $this->lock($userId)) {
                return;
            }

            $this->connection->executeStatement(
                'UPDATE semantic_memory_preference SET revision = revision + 1, index_version = index_version + 1,'
                . ' purge_pending = 1 WHERE user_id = ?',
                [$userId],
            );
            $this->connection->executeStatement(
                "UPDATE semantic_memory_excerpt SET status = 'invalid', content = '', source_ids = '[]',"
                . ' updated_at = ? WHERE user_id = ? AND ' . $this->exactIdentity('user_id', '?'),
                [time(), $userId, $userId],
            );
        });
    }

    /** Same transaction as source deletion. Null message IDs invalidates the entire thread. */
    public function invalidateSources(string $userId, string $threadId, ?array $messageIds = null): void
    {
        if (! $this->lock($userId) || $messageIds === []) {
            return;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, user_id, thread_id, source_ids, status FROM semantic_memory_excerpt'
            . ' WHERE user_id = ? AND thread_id = ?'
            . " AND status <> 'invalid'" . $this->currentRead(),
            [$userId, $threadId],
        );
        $invalidated = false;
        foreach ($rows as $row) {
            if ($row['user_id'] !== $userId || $row['thread_id'] !== $threadId) {
                continue;
            }

            $sources = json_decode($row['source_ids'], true, flags: JSON_THROW_ON_ERROR);
            if ($messageIds !== null && $row['status'] !== 'captured'
                && array_intersect($messageIds, $sources) === []) {
                continue;
            }

            $this->connection->update('semantic_memory_excerpt', [
                'status' => 'invalid', 'content' => '', 'source_ids' => '[]', 'updated_at' => time(),
            ], ['id' => $row['id']]);
            $invalidated = true;
        }

        if (! $invalidated) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE semantic_memory_preference SET purge_pending = 1 WHERE user_id = ?',
            [$userId],
        );
    }

    /**
     * Authorize at most $limit candidates, not $limit matches from an unbounded join.
     * Rotation is durable, including disabled/stale rows; updated_at also orders retry attempts.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $candidates = $this->connection->fetchAllAssociative(
            "SELECT id, user_id, updated_at FROM semantic_memory_excerpt WHERE status = 'pending'"
            . ' ORDER BY updated_at, id LIMIT ' . $limit,
        );
        if ($candidates === []) {
            return [];
        }

        $tail = $this->connection->fetchOne(
            "SELECT updated_at FROM semantic_memory_excerpt WHERE status = 'pending'"
            . ' ORDER BY updated_at DESC, id DESC LIMIT 1',
        );
        // Strictly past the existing tail, even if multiple scans run within one clock second.
        $deferredAt = max(time(), (int) $tail + 1);
        $eligible = [];
        foreach ($candidates as $candidate) {
            $source = $this->connection->transactional(function () use ($candidate, $deferredAt): ?array {
                try {
                    $owned = $this->lock($candidate['user_id']);
                } catch (RuntimeException) {
                    // A malformed collation-alias row is rotated, never authorized or erased here.
                    $owned = false;
                }

                $claimed = $this->connection->executeStatement(
                    'UPDATE semantic_memory_excerpt SET updated_at = ?'
                    . " WHERE id = ? AND user_id = ? AND status = 'pending' AND updated_at = ?"
                    . ' AND ' . $this->exactIdentity('id', '?')
                    . ' AND ' . $this->exactIdentity('user_id', '?'),
                    [$deferredAt, $candidate['id'], $candidate['user_id'], $candidate['updated_at'],
                        $candidate['id'], $candidate['user_id'],
                    ],
                );
                if ($claimed !== 1 || ! $owned || ! $this->preference($candidate['user_id'])['enabled']) {
                    return null;
                }

                $source = $this->source($candidate['user_id'], $candidate['id']);
                return $source !== null && $source['status'] === 'pending' ? $source : null;
            });
            if ($source !== null) {
                $eligible[] = $source;
            }
        }

        return $eligible;
    }

    /** @return list<string> */
    public function pendingPurges(int $limit = 100): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT user_id FROM semantic_memory_preference WHERE purge_pending = 1 ORDER BY user_id LIMIT '
            . max(1, min(1000, $limit)),
        );
    }

    /** @return list<string> Orphans needing one-time consent revocation or SQL erasure. */
    public function orphanOwners(int $limit = 100): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT p.user_id FROM semantic_memory_preference p'
            . ' LEFT JOIN account a ON a.id = p.user_id AND ' . $this->exactIdentity('a.id', 'p.user_id')
            . ' WHERE a.id IS NULL AND (p.enabled <> 0 OR EXISTS ('
            . 'SELECT 1 FROM semantic_memory_excerpt e WHERE e.user_id = p.user_id'
            . ' AND ' . $this->exactIdentity('e.user_id', 'p.user_id')
            . " AND (e.status <> 'invalid' OR e.content <> '' OR e.source_ids <> '[]')))"
            . ' ORDER BY p.user_id LIMIT ' . max(1, min(1000, $limit)),
        );
    }

    /** Retain the fenced preference row: recreating an account must not restore old consent or index versions. */
    public function eraseOrphan(string $userId): bool
    {
        return $this->connection->transactional(function () use ($userId): bool {
            if (! $this->lock($userId)) {
                return false;
            }

            $accountId = $this->connection->fetchOne(
                'SELECT id FROM account WHERE id = ?' . $this->currentRead(),
                [$userId],
            );
            if ($accountId === $userId) {
                return false;
            }

            $source = $this->connection->fetchOne(
                'SELECT id FROM semantic_memory_excerpt WHERE user_id = ?'
                . ' AND ' . $this->exactIdentity('user_id', '?')
                . " AND (status <> 'invalid' OR content <> '' OR source_ids <> '[]') LIMIT 1"
                . $this->currentRead(),
                [$userId, $userId],
            );
            if (! $this->preference($userId)['enabled'] && $source === false) {
                return false;
            }

            $this->erase($userId);
            $this->connection->update('semantic_memory_preference', ['enabled' => 0], ['user_id' => $userId]);
            return true;
        });
    }

    /** Existing successful sources only; callers additionally check current consent for reads/writes. */
    public function source(string $userId, string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT e.* FROM semantic_memory_excerpt e WHERE e.id = ?',
            [$id],
        );
        if ($row === false || $row['user_id'] !== $userId || $row['id'] !== $id) {
            return null;
        }

        // Only constant primary/unique-key predicates: owner joins can make PostgreSQL scan a whole hot thread.
        // Re-read the excerpt alongside its authorities, so the initial locator is never authorization.
        $source = $this->connection->fetchAssociative(
            'SELECT e.*, a.id AS account_owner, p.user_id AS preference_owner, p.index_version AS current_version,'
            . ' t.id AS current_turn, t.user_id AS turn_owner, t.thread_id AS turn_thread,'
            . ' t.status AS turn_status, t.deleted_at AS turn_deleted_at,'
            . ' h.user_id AS history_owner, h.thread_id AS history_thread'
            . ' FROM semantic_memory_excerpt e CROSS JOIN account a CROSS JOIN semantic_memory_preference p'
            . ' CROSS JOIN chat_turn t CROSS JOIN chat_history h'
            . ' WHERE e.id = ? AND a.id = ? AND p.user_id = ? AND t.id = ? AND h.thread_id = ?',
            [$id, $userId, $userId, $row['turn_id'], $row['thread_id']],
        );
        if ($source === false || $source['id'] !== $id || $source['user_id'] !== $userId
            || $source['account_owner'] !== $userId || $source['preference_owner'] !== $userId
            || $source['turn_owner'] !== $userId || $source['history_owner'] !== $userId
            || $source['current_turn'] !== $source['turn_id']
            || $source['turn_thread'] !== $source['thread_id'] || $source['history_thread'] !== $source['thread_id']
            || (int) $source['current_version'] !== (int) $source['index_version']
            || ! in_array($source['status'], ['pending', 'indexed'], true)
            || $source['turn_status'] !== 'succeeded' || $source['turn_deleted_at'] !== null) {
            return null;
        }

        return array_intersect_key($source, $row);
    }

    /** @return list<string> Server-derived allowlist, never supplied by the client. */
    public function threads(string $userId, string $currentThread): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT e.thread_id FROM semantic_memory_excerpt e'
            . ' JOIN account a ON a.id = e.user_id AND ' . $this->exactIdentity('a.id', 'e.user_id')
            . ' JOIN chat_history h ON h.user_id = e.user_id AND h.thread_id = e.thread_id'
            . ' AND ' . $this->exactIdentity('h.user_id', 'e.user_id')
            . ' AND ' . $this->exactIdentity('h.thread_id', 'e.thread_id')
            . ' JOIN semantic_memory_preference p ON p.user_id = e.user_id AND p.index_version = e.index_version'
            . ' AND ' . $this->exactIdentity('p.user_id', 'e.user_id')
            . " WHERE e.user_id = ? AND e.thread_id <> ? AND e.status = 'indexed' AND p.enabled = 1"
            . ' AND ' . $this->exactIdentity('e.user_id', '?'),
            [$userId, $currentThread, $userId],
        );
    }

    /** Serialize with consent/deletion transactions, including under MySQL REPEATABLE READ. */
    public function lock(string $userId): bool
    {
        if (! $this->connection->isTransactionActive()) {
            throw new RuntimeException('Semantic memory hook requires the business transaction');
        }

        $this->connection->executeStatement(
            'UPDATE semantic_memory_preference SET revision = revision WHERE user_id = ?',
            [$userId],
        );
        $owner = $this->connection->fetchOne(
            'SELECT user_id FROM semantic_memory_preference WHERE user_id = ?' . $this->currentRead(),
            [$userId],
        );
        if ($owner !== false && $owner !== $userId) {
            throw new RuntimeException('Semantic memory owner mismatch');
        }

        return $owner !== false;
    }

    /** SQL collation is not an authorization rule; keep indexed equality predicates alongside these guards. */
    private function exactIdentity(string $left, string $right): string
    {
        $platform = strtolower($this->connection->getDatabasePlatform()::class);
        if (str_contains($platform, 'postgresql')) {
            return "convert_to({$left}, 'UTF8') = convert_to({$right}, 'UTF8')";
        }

        $type = str_contains($platform, 'sqlite') ? 'BLOB' : 'BINARY';
        return "CAST({$left} AS {$type}) = CAST({$right} AS {$type})";
    }

    private function currentRead(): string
    {
        return $this->connection->isTransactionActive()
            && ! str_contains(strtolower($this->connection->getDatabasePlatform()::class), 'sqlite')
            ? ' FOR UPDATE' : '';
    }
}
