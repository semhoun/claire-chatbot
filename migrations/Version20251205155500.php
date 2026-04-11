<?php

declare(strict_types=1);

namespace Migrations;

use App\BaseMigration;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;

/**
 * Consolidated migration: create final `file` table with proper schema.
 * This replaces previous incremental migrations that created `chat_file`,
 * dropped `thread_id`, and renamed to `file`.
 */
final class Version20251205155500 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Create final `file` table (user-scoped files) with FK and indexes';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $userTable = $this->quoteUserTable($platform);

        if ($this->isSqlitePlatform($platform)) {
            $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS "file" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT,
    filename TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL DEFAULT 0,
    file_id TEXT NOT NULL,
    created_at DATETIME DEFAULT (CURRENT_TIMESTAMP),
    CONSTRAINT fk_file_user FOREIGN KEY (user_id) REFERENCES {$userTable}(id) ON DELETE CASCADE ON UPDATE NO ACTION
);
SQL);
            $this->addSql('CREATE INDEX IF NOT EXISTS idx_cf_user_id ON "file"(user_id)');

            return;
        }

        if ($this->isPostgreSqlPlatform($platform)) {
            $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS "file" (
    id BIGSERIAL PRIMARY KEY,
    user_id VARCHAR(64),
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(191) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    file_id VARCHAR(36) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_file_user FOREIGN KEY (user_id) REFERENCES {$userTable}(id) ON DELETE CASCADE
);
SQL);

            $this->addSql('CREATE INDEX IF NOT EXISTS idx_cf_user_id ON "file"(user_id)');

            return;
        }

        $this->addSql(<<<SQL
CREATE TABLE IF NOT EXISTS `file` (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(64),
    filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(191) NOT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    file_id VARCHAR(36) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cf_user_id (user_id),
    CONSTRAINT fk_file_user FOREIGN KEY (user_id) REFERENCES {$userTable}(id) ON DELETE CASCADE ON UPDATE NO ACTION
);
SQL);
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($this->isMySqlPlatform($platform)) {
            $this->addSql('DROP TABLE IF EXISTS `file`');

            return;
        }

        $this->addSql('DROP TABLE IF EXISTS "file"');
    }

    private function quoteUserTable(AbstractPlatform $platform): string
    {
        return match (true) {
            $this->isMySqlPlatform($platform) => '`user`',
            default => '"user"',
        };
    }
}
