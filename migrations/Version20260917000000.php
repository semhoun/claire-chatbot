<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260917000000 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Create durable chat turn checkpoints and history write fences';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(! $this->isMySqlPlatform($platform)
            && ! $this->isPostgreSqlPlatform($platform)
            && ! $this->isSqlitePlatform($platform), 'Unsupported journal database');
        $text = $this->isMySqlPlatform($platform) ? 'LONGTEXT' : 'TEXT';
        $this->addSql('ALTER TABLE chat_history ADD COLUMN revision BIGINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE chat_history ADD COLUMN current_turn_id VARCHAR(128) DEFAULT NULL');
        $this->addSql('CREATE TABLE chat_turn ('
            . 'id VARCHAR(128) NOT NULL PRIMARY KEY, user_id VARCHAR(255) NOT NULL, '
            . 'thread_id VARCHAR(128) NOT NULL, channel VARCHAR(32) NOT NULL, '
            . 'generation_id VARCHAR(128) NOT NULL, submission_id VARCHAR(128) DEFAULT NULL, '
            . 'status VARCHAR(16) NOT NULL, checkpoint ' . $text . ' DEFAULT NULL, '
            . 'notification ' . $text . ' NOT NULL, history_revision BIGINT NOT NULL, '
            . 'revision BIGINT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, '
            . 'completed_at BIGINT DEFAULT NULL, deleted_at BIGINT DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_chat_turn_owner ON chat_turn (user_id, thread_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_chat_turn_submission ON chat_turn (user_id, submission_id)');
        $this->addSql('CREATE INDEX idx_chat_turn_running ON chat_turn (status, created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_turn');
        $this->addSql('ALTER TABLE chat_history DROP COLUMN current_turn_id');
        $this->addSql('ALTER TABLE chat_history DROP COLUMN revision');
    }
}
