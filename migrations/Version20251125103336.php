<?php

declare(strict_types=1);

namespace Migrations;

use App\BaseMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20251125103336 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Create chat_history table with FK to account.id';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $userTable = 'account';

        if ($this->isSqlitePlatform($platform)) {
            $this->addSql(
                <<<EOT
CREATE TABLE IF NOT EXISTS chat_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT NOT NULL,
    thread_id TEXT NOT NULL,
    messages TEXT NOT NULL,
    title TEXT,
    summary TEXT,
    created_at DATETIME DEFAULT (CURRENT_TIMESTAMP),
    updated_at DATETIME DEFAULT (CURRENT_TIMESTAMP),
    CONSTRAINT fk_chat_history_user FOREIGN KEY (user_id) REFERENCES {$userTable}(id) ON DELETE CASCADE ON UPDATE NO ACTION
);

-- Indexes (separate statements in SQLite)
CREATE UNIQUE INDEX IF NOT EXISTS uk_thread_id ON chat_history(thread_id);
CREATE INDEX IF NOT EXISTS idx_user_id ON chat_history(user_id);
CREATE INDEX IF NOT EXISTS idx_thread_id ON chat_history(thread_id);
EOT
            );

            return;
        }

        if ($this->isPostgreSqlPlatform($platform)) {
            $this->addSql(
                <<<EOT
CREATE TABLE IF NOT EXISTS chat_history (
    id BIGSERIAL PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL,
    thread_id VARCHAR(128) NOT NULL,
    messages TEXT NOT NULL,
    title TEXT,
    summary TEXT,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_chat_history_user FOREIGN KEY (user_id) REFERENCES {$userTable}(id) ON DELETE CASCADE
);
EOT
            );

            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uk_thread_id ON chat_history(thread_id)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_user_id ON chat_history(user_id)');
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_thread_id ON chat_history(thread_id)');

            return;
        }

        $this->addSql(
            <<<EOT
CREATE TABLE IF NOT EXISTS chat_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL,
    thread_id VARCHAR(128) NOT NULL,
    messages LONGTEXT NOT NULL,
    title TEXT,
    summary TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_thread_id (thread_id),
    INDEX idx_thread_id (thread_id),
    INDEX idx_user_id (user_id),
    CONSTRAINT fk_chat_history_user FOREIGN KEY (user_id) REFERENCES {$userTable}(id) ON DELETE CASCADE ON UPDATE NO ACTION
);
EOT
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_history');
    }
}
