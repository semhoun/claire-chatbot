<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;
use RuntimeException;

/** Callers hold the conversation lock, including during agent execution. No LLM work in callbacks. */
final readonly class ChatTurnJournal
{
    private const array CHECKPOINT_FIELDS = [
        'messages', 'display_messages', 'display_messages_count', 'title', 'summary', 'updated_at', 'stored_messages',
    ];

    public function __construct(private Connection $connection, private ?ChatStopRequests $stops = null)
    {
    }

    /**
     * Only entered=true authorizes an agent call; existing IDs return entered=false, even when running.
     *
     * @param array<string, string|int|null> $notification Destination identifiers only, never tokens/content.
     * @param (callable(Connection): void)|null $atomic DB-only fence, committed with the checkpoint.
     *
     * @return array<string, mixed> CamelCase metadata, without the private checkpoint.
     */
    public function begin(
        string $id,
        string $userId,
        string $threadId,
        string $channel,
        string $generationId,
        ?string $submissionId = null,
        array $notification = [],
        ?callable $atomic = null,
    ): array {
        foreach ([$id, $userId, $threadId, $generationId] as $value) {
            if ($value === '' || strlen($value) > 128) {
                throw new \InvalidArgumentException('Invalid chat turn identity');
            }
        }
        if (! in_array($channel, ['web', 'telegram'], true)
            || ($submissionId !== null && ($submissionId === '' || strlen($submissionId) > 128))) {
            throw new \InvalidArgumentException('Invalid chat turn channel or submission');
        }
        foreach ($notification as $key => $value) {
            if (! in_array($key, ['botId', 'updateId', 'chatId', 'messageThreadId', 'replyToMessageId',
                'messageId', 'sessionId',
            ], true)
                || (! is_string($value) && ! is_int($value) && $value !== null)) {
                throw new \InvalidArgumentException('Invalid chat turn notification context');
            }
        }
        return $this->transaction(function () use (
            $id,
            $userId,
            $threadId,
            $channel,
            $generationId,
            $submissionId,
            $notification,
            $atomic,
        ): array {
            $existing = $this->get($id);
            if ($existing !== null) {
                foreach (compact('userId', 'threadId', 'channel', 'generationId', 'submissionId') as $key => $value) {
                    if ($existing[$key] !== $value) {
                        throw new RuntimeException('Chat turn identity conflict');
                    }
                }
                return $existing + ['entered' => false];
            }
            if ($this->connection->fetchOne(
                'SELECT id FROM chat_turn WHERE user_id = ? AND thread_id = ?'
                . " AND (status = 'running' OR deleted_at IS NOT NULL)",
                [$userId, $threadId],
            ) !== false) {
                throw new RuntimeException('Chat turn requires recovery');
            }
            $history = $this->history($userId, $threadId);
            if ($history === false) {
                $this->connection->insert('chat_history', [
                    'user_id' => $userId, 'thread_id' => $threadId, 'messages' => '[]',
                    'display_messages' => '[]', 'display_messages_count' => 0,
                    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $history = $this->history($userId, $threadId);
            }
            if ($history['current_turn_id'] !== null) {
                throw new RuntimeException('Chat history has an active turn');
            }
            $checkpoint = ['version' => 2, 'history' => array_intersect_key(
                $history,
                array_flip(self::CHECKPOINT_FIELDS),
            ),
            ];
            $revision = (int) $history['revision'];
            $this->check($this->connection->executeStatement(
                'UPDATE chat_history SET current_turn_id = ?, revision = revision + 1'
                . ' WHERE user_id = ? AND thread_id = ? AND revision = ? AND current_turn_id IS NULL',
                [$id, $userId, $threadId, $revision],
            ));
            $now = time();
            $this->connection->insert('chat_turn', [
                'id' => $id, 'user_id' => $userId, 'thread_id' => $threadId, 'channel' => $channel,
                'generation_id' => $generationId, 'submission_id' => $submissionId, 'status' => 'running',
                'checkpoint' => json_encode($checkpoint, JSON_THROW_ON_ERROR),
                'notification' => json_encode($notification, JSON_THROW_ON_ERROR),
                'history_revision' => $revision + 1, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($atomic !== null) {
                $atomic($this->connection);
            }
            return $this->get($id) + ['entered' => true];
        });
    }

    /**
     * @return array{id: string, userId: string, threadId: string, channel: string, generationId: string,
     * submissionId: ?string, status: string, notification: array<string, string|int|null>,
     * historyRevision: int, revision: int,
     * createdAt: int, updatedAt: int, completedAt: ?int, deletedAt: ?int}|null
     */
    public function get(string $id, bool $lock = false): ?array
    {
        $suffix = $lock && ! $this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform
            ? ' FOR UPDATE' : '';
        $row = $this->connection->fetchAssociative('SELECT * FROM chat_turn WHERE id = ?' . $suffix, [$id]);
        if ($row === false) {
            return null;
        }
        if ($row['id'] !== $id) {
            throw new RuntimeException('Chat turn identity conflict');
        }
        $record = [];
        foreach ($row as $column => $value) {
            if ($column === 'checkpoint') {
                continue;
            }
            $key = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $column))));
            $record[$key] = match ($column) {
                'revision', 'history_revision', 'created_at', 'updated_at' => (int) $value,
                'completed_at', 'deleted_at' => $value === null ? null : (int) $value,
                'notification' => json_decode($value, true, flags: JSON_THROW_ON_ERROR),
                default => $value,
            };
        }
        return $record;
    }

    /**
     * @param (callable(Connection): void)|null $atomic
     *
     * @return array<string, mixed>
     */
    public function succeed(string $id, string $userId, ?callable $atomic = null): array
    {
        return $this->complete($id, $userId, 'succeeded', $atomic);
    }

    /**
     * The callback runs once, after terminal SQL updates, within the SAME transaction.
     * It may persist stopped messages or a success outbox; never perform network I/O here.
     *
     * @param (callable(Connection, string): void)|null $atomic
     *
     * @return array<string, mixed>
     */
    public function complete(string $id, string $userId, string $proposedStatus, ?callable $atomic = null): array
    {
        if (! in_array($proposedStatus, ['succeeded', 'stopped', 'rolled_back'], true)) {
            throw new \InvalidArgumentException('Invalid chat turn terminal status');
        }
        return $this->finish($id, $userId, $proposedStatus, $atomic);
    }

    /** @return array<string, mixed> */
    public function rollback(string $id, string $userId): array
    {
        return $this->complete($id, $userId, 'rolled_back');
    }

    /** @return list<array<string, mixed>> Oldest running turns first; acquire their locks before recovery. */
    public function running(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Invalid chat turn scan limit');
        }
        $ids = $this->connection->fetchFirstColumn(
            "SELECT id FROM chat_turn WHERE status = 'running' ORDER BY created_at, id LIMIT " . $limit,
        );
        return array_values(array_filter(array_map($this->get(...), $ids)));
    }

    /** Neutralize recovery inside the same transaction as explicit deletion, under the conversation lock. */
    public function neutralize(string $userId, string $threadId): void
    {
        if (! $this->connection->isTransactionActive()) {
            throw new RuntimeException('Chat turn deletion requires a transaction');
        }
        // Deletion is a privacy operation even with memory globally disabled. Probe
        // through savepoints: deployed temporary schemas work, and legacy PG remains usable.
        $missing = 0;
        foreach (['semantic_memory_preference', 'semantic_memory_excerpt'] as $table) {
            try {
                $this->connection->transactional(function () use ($table): void {
                    $this->connection->executeQuery('SELECT 1 FROM ' . $table . ' WHERE 1 = 0')->free();
                });
            } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
                ++$missing;
            }
        }
        if ($missing === 0) {
            new SemanticMemoryRegistry($this->connection)->invalidateSources($userId, $threadId);
        } elseif ($missing !== 2) {
            throw new RuntimeException('Semantic memory schema is incomplete; refusing source deletion');
        }
        $this->connection->executeStatement(
            "UPDATE chat_turn SET status = 'rolled_back', checkpoint = NULL, revision = revision + 1,"
            . " updated_at = ?, completed_at = ? WHERE user_id = ? AND thread_id = ? AND status = 'running'",
            [time(), time(), $userId, $threadId],
        );
        $this->connection->executeStatement(
            'UPDATE chat_turn SET deleted_at = ? WHERE user_id = ? AND thread_id = ? AND deleted_at IS NULL',
            [time(), $userId, $threadId],
        );
    }

    /**
     * @param (callable(Connection): void)|null $atomic
     *
     * @return array<string, mixed>
     */
    private function finish(string $id, string $userId, string $status, ?callable $atomic = null): array
    {
        return $this->transaction(function () use ($id, $userId, $status, $atomic): array {
            $turn = $this->get($id, true);
            if ($turn === null || $turn['userId'] !== $userId) {
                throw new RuntimeException('Chat turn owner mismatch or missing turn');
            }
            if ($turn['status'] !== 'running') {
                return $turn;
            }
            if ($this->stops !== null) {
                $decision = $this->stops->arbitrateTerminal(
                    $userId,
                    $turn['threadId'],
                    $turn['channel'],
                    $turn['generationId'],
                    $status
                );
                if (! $decision['won']) {
                    // A running journal with a separately terminal registry is inconsistent,
                    // not permission to replay finalization or invent a journal result.
                    throw new RuntimeException('Chat turn terminal conflict; recovery required');
                }
                $status = $decision['status'];
            }
            $history = $this->history($userId, $turn['threadId']);
            if ($history === false || $history['current_turn_id'] !== $id
                || (int) $history['revision'] < $turn['historyRevision']) {
                throw new RuntimeException('Chat turn history conflict; recovery required');
            }
            $data = ['current_turn_id' => null, 'revision' => (int) $history['revision'] + 1];
            if ($status === 'rolled_back') {
                $checkpoint = json_decode($this->connection->fetchOne(
                    'SELECT checkpoint FROM chat_turn WHERE id = ?',
                    [$id],
                ), true, flags: JSON_THROW_ON_ERROR);
                $version = $checkpoint['version'] ?? null;
                $fields = $version === 1 ? array_slice(self::CHECKPOINT_FIELDS, 0, -1) : self::CHECKPOINT_FIELDS;
                if (! in_array($version, [1, 2], true)
                    || array_keys($checkpoint['history'] ?? []) !== $fields) {
                    throw new RuntimeException('Unsupported chat turn checkpoint');
                }
                $data += $checkpoint['history'];
                if ($version === 1) {
                    // A v3 checkpoint has no canonical transcript: rebuild from restored projections on next write.
                    $data['stored_messages'] = null;
                }
            }
            $this->check($this->connection->update('chat_history', $data, [
                'user_id' => $userId, 'thread_id' => $turn['threadId'],
                'revision' => $history['revision'], 'current_turn_id' => $id,
            ]));
            $this->check($this->connection->update('chat_turn', [
                'status' => $status, 'checkpoint' => null,
                'revision' => $turn['revision'] + 1, 'updated_at' => time(), 'completed_at' => time(),
            ], ['id' => $id, 'revision' => $turn['revision'], 'status' => 'running']));
            if ($atomic !== null) {
                $atomic($this->connection, $status);
            }
            return $this->get($id);
        });
    }

    /** @return array<string, mixed>|false */
    private function history(string $userId, string $threadId): array|false
    {
        $row = $this->connection->fetchAssociative(
            'SELECT user_id, thread_id, messages, display_messages, display_messages_count, title, summary, updated_at, stored_messages,'
            . ' revision, current_turn_id FROM chat_history WHERE user_id = ? AND thread_id = ?',
            [$userId, $threadId],
        );
        if ($row !== false && ($row['user_id'] !== $userId || $row['thread_id'] !== $threadId)) {
            throw new RuntimeException('Chat history owner or thread mismatch');
        }
        return $row;
    }

    /**
     * @param callable(): array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private function transaction(callable $operation): array
    {
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            throw new RuntimeException('Chat turn journal requires an independent short commit');
        }
        return $this->connection->transactional($operation);
    }

    private function check(int $affected): void
    {
        if ($affected !== 1) {
            throw new RuntimeException('Chat turn revision conflict');
        }
    }
}
