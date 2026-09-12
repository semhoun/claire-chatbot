<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260912130000 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Create durable Telegram generation journal';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(! $this->isMySqlPlatform($platform)
            && ! $this->isPostgreSqlPlatform($platform)
            && ! $this->isSqlitePlatform($platform), 'Unsupported journal database');
        $text = $this->isMySqlPlatform($platform) ? 'LONGTEXT' : 'TEXT';
        $this->addSql('CREATE TABLE telegram_generation ('
            . 'id VARCHAR(64) NOT NULL PRIMARY KEY, '
            . 'bot_id VARCHAR(255) NOT NULL, update_id VARCHAR(255) NOT NULL, '
            . 'user_id VARCHAR(255) NOT NULL, thread_id VARCHAR(255) NOT NULL, '
            . 'attempted SMALLINT NOT NULL DEFAULT 0, delivered SMALLINT NOT NULL DEFAULT 0, '
            . 'compacted SMALLINT NOT NULL DEFAULT 0, response ' . $text . ' DEFAULT NULL, '
            . 'deliveries ' . $text . ' DEFAULT NULL, completed_at BIGINT DEFAULT NULL, '
            . 'created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, revision INTEGER NOT NULL)');
        $this->addSql('CREATE INDEX idx_telegram_generation_owner ON telegram_generation (user_id, thread_id)');
        $this->addSql('CREATE INDEX idx_telegram_generation_retention '
            . 'ON telegram_generation (delivered, compacted, completed_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE telegram_generation');
    }
}
