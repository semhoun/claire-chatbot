<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;
use RuntimeException;

/** Callers hold the conversation lock, including during agent execution. No LLM work in callbacks. */
final readonly class ChatTurnJournal
{
    private const array CHECKPOINT_FIELDS = [
        'messages', 'display_messages', 'display_messages_count', 'title', 'summary', 'updated_at',
    ];

    public function __construct(private Connection $connection)
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
            $checkpoint = ['version' => 1, 'history' => array_intersect_key(
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
    public function get(string $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM chat_turn WHERE id = ?', [$id]);
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
        return $this->finish($id, $userId, false, $atomic);
    }

    /** @return array<string, mixed> */
    public function rollback(string $id, string $userId): array
    {
        return $this->finish($id, $userId, true);
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
    private function finish(string $id, string $userId, bool $rollback, ?callable $atomic = null): array
    {
        return $this->transaction(function () use ($id, $userId, $rollback, $atomic): array {
            $turn = $this->get($id);
            if ($turn === null || $turn['userId'] !== $userId) {
                throw new RuntimeException('Chat turn owner mismatch or missing turn');
            }
            if ($turn['status'] !== 'running') {
                return $turn;
            }
            $history = $this->history($userId, $turn['threadId']);
            if ($history === false || $history['current_turn_id'] !== $id
                || (int) $history['revision'] < $turn['historyRevision']) {
                throw new RuntimeException('Chat turn history conflict; recovery required');
            }
            $data = ['current_turn_id' => null, 'revision' => (int) $history['revision'] + 1];
            if ($rollback) {
                $checkpoint = json_decode($this->connection->fetchOne(
                    'SELECT checkpoint FROM chat_turn WHERE id = ?',
                    [$id],
                ), true, flags: JSON_THROW_ON_ERROR);
                if (($checkpoint['version'] ?? null) !== 1
                    || array_keys($checkpoint['history'] ?? []) !== self::CHECKPOINT_FIELDS) {
                    throw new RuntimeException('Unsupported chat turn checkpoint');
                }
                $data += $checkpoint['history'];
            }
            $this->check($this->connection->update('chat_history', $data, [
                'user_id' => $userId, 'thread_id' => $turn['threadId'],
                'revision' => $history['revision'], 'current_turn_id' => $id,
            ]));
            $this->check($this->connection->update('chat_turn', [
                'status' => $rollback ? 'rolled_back' : 'succeeded', 'checkpoint' => null,
                'revision' => $turn['revision'] + 1, 'updated_at' => time(), 'completed_at' => time(),
            ], ['id' => $id, 'revision' => $turn['revision'], 'status' => 'running']));
            if ($atomic !== null) {
                $atomic($this->connection);
            }
            return $this->get($id);
        });
    }

    /** @return array<string, mixed>|false */
    private function history(string $userId, string $threadId): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT messages, display_messages, display_messages_count, title, summary, updated_at,'
            . ' revision, current_turn_id FROM chat_history WHERE user_id = ? AND thread_id = ?',
            [$userId, $threadId],
        );
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
