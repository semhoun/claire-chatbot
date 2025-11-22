<?php

declare(strict_types=1);

namespace app;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251122210810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'NeuronAI table chat history';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            $this->addSql(
                <<<EOT
-- SQLite-compatible schema
CREATE TABLE IF NOT EXISTS chat_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  thread_id TEXT NOT NULL,
  messages TEXT NOT NULL,
  created_at DATETIME DEFAULT (CURRENT_TIMESTAMP),
  updated_at DATETIME DEFAULT (CURRENT_TIMESTAMP)
);

-- Indexes (separate statements in SQLite)
CREATE UNIQUE INDEX IF NOT EXISTS uk_thread_id ON chat_history(thread_id);
CREATE INDEX IF NOT EXISTS idx_thread_id ON chat_history(thread_id);
EOT
            );
        } else {
            $this->addSql(
                <<<EOT
CREATE TABLE IF NOT EXISTS chat_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_id VARCHAR(255) NOT NULL,
  messages LONGTEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 
  UNIQUE KEY uk_thread_id (thread_id),
  INDEX idx_thread_id (thread_id)
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
