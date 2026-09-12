<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use RuntimeException;

/** SQL is authoritative; callers hold the telegram-journal/id advisory lock. */
final readonly class TelegramJournal
{
    private const array FIELDS = [
        'botId' => 'bot_id', 'updateId' => 'update_id', 'userId' => 'user_id', 'threadId' => 'thread_id',
        'attempted' => 'attempted', 'delivered' => 'delivered', 'compacted' => 'compacted',
        'response' => 'response', 'completedAt' => 'completed_at',
        'createdAt' => 'created_at', 'updatedAt' => 'updated_at', '_revision' => 'revision',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public static function id(string $botId, string $updateId): string
    {
        return hash('sha256', json_encode([$botId, $updateId], JSON_THROW_ON_ERROR));
    }

    public function now(): int
    {
        $platform = $this->connection->getDatabasePlatform();
        return (int) $this->connection->fetchOne(match (true) {
            $platform instanceof PostgreSQLPlatform => 'SELECT FLOOR(EXTRACT(EPOCH FROM clock_timestamp()))',
            $platform instanceof AbstractMySQLPlatform => 'SELECT UNIX_TIMESTAMP()',
            $platform instanceof SQLitePlatform => "SELECT CAST(strftime('%s', 'now') AS INTEGER)",
            default => throw new RuntimeException('Unsupported journal database'),
        });
    }

    /** @return array<string, mixed>|null */
    public function load(string $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM telegram_generation WHERE id = ?', [$id]);
        if ($row === false) {
            return null;
        }

        $record = ['deliveries' => json_decode($row['deliveries'] ?? '{}', true, flags: JSON_THROW_ON_ERROR)];
        foreach (self::FIELDS as $field => $column) {
            if ($field === 'response' && $row[$column] === null) {
                continue;
            }

            $record[$field] = match ($field) {
                'attempted', 'delivered', 'compacted' => (bool) $row[$column],
                'createdAt', 'updatedAt', '_revision' => (int) $row[$column],
                'completedAt' => $row[$column] === null ? null : (int) $row[$column],
                default => $row[$column],
            };
        }

        return $record;
    }

    /** @param array<string, mixed> $record */
    public function save(string $id, array &$record): void
    {
        // An outer transaction would make the pre-agent fence non-durable.
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            throw new RuntimeException('Telegram journal requires an independent short commit');
        }

        $next = $record;
        $now = $this->now();
        $revision = $record['_revision'] ?? 0;
        $next += ['attempted' => false, 'delivered' => false, 'compacted' => false, 'createdAt' => $now];
        $next['updatedAt'] = $now;
        $next['_revision'] = $revision + 1;
        if ($next['delivered'] && ! isset($next['completedAt'])) {
            $next['completedAt'] = $now;
        }

        $data = [];
        foreach (self::FIELDS as $field => $column) {
            $data[$column] = in_array($field, ['attempted', 'delivered', 'compacted'], true)
                ? (int) $next[$field] : ($next[$field] ?? null);
        }

        $data['deliveries'] = json_encode($next['deliveries'] ?? [], JSON_THROW_ON_ERROR);
        $this->connection->transactional(function () use ($id, $data, $revision): void {
            if ($revision === 0) {
                $this->connection->insert('telegram_generation', ['id' => $id] + $data);
                return;
            }

            if ($this->connection->update('telegram_generation', $data, ['id' => $id, 'revision' => $revision]) !== 1) {
                throw new RuntimeException('Telegram journal revision conflict');
            }
        });
        $record = $next;
    }
}
