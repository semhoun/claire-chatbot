<?php

declare(strict_types=1);

namespace App\Test\Support;

use Doctrine\DBAL\Connection;
use Migrations\Version20260917000000;

final class ChatTurnSqlSchema
{
    public static function create(Connection $connection, bool $temporary = false): void
    {
        $connection->executeStatement('CREATE ' . ($temporary ? 'TEMPORARY ' : '') . 'TABLE IF NOT EXISTS chat_history ('
            . 'user_id VARCHAR(255) NOT NULL, thread_id VARCHAR(128) PRIMARY KEY, messages TEXT, display_messages TEXT,'
            . ' display_messages_count INTEGER DEFAULT 0, title TEXT, summary TEXT,'
            . ' created_at TEXT, updated_at TEXT)');
        require_once __DIR__ . '/TelegramSqlSchema.php';
        $migration = new Version20260917000000($connection, new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) {
            $sql = $query->getStatement();
            if ($temporary) {
                $sql = str_replace('CREATE TABLE ', 'CREATE TEMPORARY TABLE ', $sql);
            }
            $connection->executeStatement($sql, $query->getParameters(), $query->getTypes());
        }
    }
}
