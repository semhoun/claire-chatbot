<?php

declare(strict_types=1);

namespace app;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Consolidated migration: create final `file` table with proper schema.
 * This replaces previous incremental migrations that created `chat_file`,
 * dropped `thread_id`, and renamed to `file`.
 */
final class Version20251205155500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create final `file` table (user-scoped files) with FK and indexes';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            // SQLite DDL
            $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS "file" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT NOT NULL,
    filename TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    content BLOB NOT NULL,
    created_at DATETIME DEFAULT (CURRENT_TIMESTAMP),
    token TEXT NULL,
    CONSTRAINT fk_file_user FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE ON UPDATE NO ACTION
);
SQL);
            // Indexes
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_cf_user_id ON "file"(user_id)');
            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS ux_file_token ON "file" (token)');
        } else {
            // MySQL and others
            $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS `file` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(64) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(191) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    content LONGBLOB NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    token VARCHAR(36) NOT NULL,
    INDEX idx_cf_user_id (user_id),
    UNIQUE ux_file_tole (token),
    CONSTRAINT fk_file_user FOREIGN KEY (user_id) REFERENCES user(id) ON DELETE CASCADE ON UPDATE NO ACTION
);
SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE `file`');
    }
}
