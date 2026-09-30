<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260930000100 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Register accepted chat generations and durable cooperative stop requests';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(! $this->isMySqlPlatform($platform)
            && ! $this->isPostgreSqlPlatform($platform)
            && ! $this->isSqlitePlatform($platform), 'Unsupported stop request database');
        $this->addSql('CREATE TABLE chat_stop_request ('
            . 'id VARCHAR(64) NOT NULL PRIMARY KEY, user_id VARCHAR(255) NOT NULL, '
            . 'thread_id VARCHAR(128) NOT NULL, channel VARCHAR(32) NOT NULL, '
            . 'generation_id VARCHAR(128) NOT NULL, status VARCHAR(16) NOT NULL, '
            . 'stop_requested SMALLINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, '
            . 'requested_at BIGINT DEFAULT NULL, completed_at BIGINT DEFAULT NULL)');
        $this->addSql('CREATE INDEX idx_chat_stop_cleanup ON chat_stop_request (completed_at, id)');
        $this->addSql('CREATE INDEX idx_chat_stop_owner ON chat_stop_request (user_id, thread_id, channel)');
        $this->addSql('CREATE INDEX idx_stop_active_owner ON chat_stop_request '
            . '(user_id, channel, status, generation_id, thread_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_stop_request');
    }
}
