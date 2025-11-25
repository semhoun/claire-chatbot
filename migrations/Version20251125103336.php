<?php

declare(strict_types=1);

namespace app;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251125103336 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create chat_history table with FK to user.id';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            $this->addSql(
                <<<EOT
CREATE TABLE IF NOT EXISTS chat_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id TEXT NOT NULL,
  thread_id TEXT NOT NULL,
  messages TEXT NOT NULL,
  title TEXT,
  summarize TEXT,
  created_at DATETIME DEFAULT (CURRENT_TIMESTAMP),
  updated_at DATETIME DEFAULT (CURRENT_TIMESTAMP),
  CONSTRAINT fk_chat_history_user FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE ON UPDATE NO ACTION
);

-- Indexes (separate statements in SQLite)
CREATE UNIQUE INDEX IF NOT EXISTS uk_thread_id ON chat_history(thread_id);
CREATE INDEX IF NOT EXISTS idx_user_id ON chat_history(user_id);
CREATE INDEX IF NOT EXISTS idx_thread_id ON chat_history(thread_id);
EOT
            );
        } else {
            $this->addSql(
                <<<EOT
CREATE TABLE IF NOT EXISTS chat_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL,
    thread_id VARCHAR(128) NOT NULL,
    messages LONGTEXT NOT NULL,
    title TEXT,
    summarize TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_thread_id (thread_id),
    INDEX idx_thread_id (thread_id),
    INDEX idx_user_id (user_id),
    CONSTRAINT fk_chat_history_user FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE ON UPDATE NO ACTION
);
EOT
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_history');
    }
}
