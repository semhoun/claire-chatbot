<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;

/**
 * Create telegram_session table for storing Telegram user sessions.
 *
 * Each Telegram user has exactly one session stored by their telegram_id.
 */

final class Version20260315160000 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Create telegram_session table for database-backed Telegram sessions';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($this->isSqlitePlatform($platform)) {
            $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS "telegram_session" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id TEXT NOT NULL UNIQUE,
    session_data TEXT NOT NULL DEFAULT '{}',
    created_at DATETIME DEFAULT (CURRENT_TIMESTAMP),
    updated_at DATETIME DEFAULT (CURRENT_TIMESTAMP)
);
SQL);
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_telegram_id ON "telegram_session"(telegram_id)');
            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uk_telegram_id ON "telegram_session"(telegram_id)');

            return;
        }

        if ($this->isPostgreSqlPlatform($platform)) {
            $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS "telegram_session" (
    id BIGSERIAL PRIMARY KEY,
    telegram_id VARCHAR(64) NOT NULL UNIQUE,
    session_data TEXT NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);

            $this->addSql('CREATE INDEX IF NOT EXISTS idx_telegram_id ON "telegram_session"(telegram_id)');
            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uk_telegram_id ON "telegram_session"(telegram_id)');

            return;
        }

        $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS `telegram_session` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telegram_id VARCHAR(64) NOT NULL UNIQUE,
    session_data LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_telegram_id (telegram_id),
    UNIQUE INDEX uk_telegram_id (telegram_id)
);
SQL);
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($this->isMySqlPlatform($platform)) {
            $this->addSql('DROP TABLE IF EXISTS `telegram_session`');

            return;
        }

        $this->addSql('DROP TABLE IF EXISTS "telegram_session"');
    }
}
