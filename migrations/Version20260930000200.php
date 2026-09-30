<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260930000200 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Create opt-in semantic memory consent and eligible-turn outbox';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(! $this->isMySqlPlatform($platform)
            && ! $this->isPostgreSqlPlatform($platform)
            && ! $this->isSqlitePlatform($platform), 'Unsupported semantic memory database');
        $text = $this->isMySqlPlatform($platform) ? 'LONGTEXT' : 'TEXT';
        $this->addSql('CREATE TABLE semantic_memory_preference ('
            . 'user_id VARCHAR(255) NOT NULL PRIMARY KEY, enabled SMALLINT NOT NULL DEFAULT 0, '
            . 'revision BIGINT NOT NULL DEFAULT 0, index_version BIGINT NOT NULL DEFAULT 1, '
            . 'purge_pending SMALLINT NOT NULL DEFAULT 0)');
        $this->addSql('CREATE TABLE semantic_memory_excerpt ('
            . 'id VARCHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(255) NOT NULL, '
            . 'thread_id VARCHAR(128) NOT NULL, turn_id VARCHAR(128) NOT NULL, '
            . 'consent_revision BIGINT NOT NULL, index_version BIGINT NOT NULL, '
            . 'source_ids ' . $text . ' NOT NULL, content ' . $text . ' NOT NULL, '
            . 'model VARCHAR(255) DEFAULT NULL, dimensions INTEGER DEFAULT NULL, '
            . 'status VARCHAR(16) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_semantic_memory_turn '
            . 'ON semantic_memory_excerpt (user_id, turn_id, consent_revision)');
        $this->addSql('CREATE INDEX idx_semantic_memory_owner '
            . 'ON semantic_memory_excerpt (user_id, thread_id, status)');
        $this->addSql('CREATE INDEX idx_semantic_memory_outbox '
            . 'ON semantic_memory_excerpt (status, updated_at, id)');
        $this->addSql('CREATE INDEX idx_semantic_memory_purge '
            . 'ON semantic_memory_preference (purge_pending, user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE semantic_memory_excerpt');
        $this->addSql('DROP TABLE semantic_memory_preference');
    }
}
