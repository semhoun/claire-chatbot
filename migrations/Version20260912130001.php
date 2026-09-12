<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260912130001 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Durable Telegram queue receipts, independent of Redis';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(! $this->isMySqlPlatform($platform)
            && ! $this->isPostgreSqlPlatform($platform) && ! $this->isSqlitePlatform($platform),
            'Unsupported outbox database');
        $text = $this->isMySqlPlatform($platform) ? 'LONGTEXT' : 'TEXT';
        $this->addSql("CREATE TABLE queue_outbox (
            id VARCHAR(36) NOT NULL PRIMARY KEY,
            event_key VARCHAR(64) NOT NULL UNIQUE,
            job_class VARCHAR(255) NOT NULL,
            queue_name VARCHAR(255) NOT NULL,
            payload {$text} DEFAULT NULL,
            status VARCHAR(16) NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            available_at BIGINT NOT NULL,
            lease_until BIGINT DEFAULT NULL,
            lease_token VARCHAR(64) DEFAULT NULL,
            published_at BIGINT DEFAULT NULL,
            completed_at BIGINT DEFAULT NULL,
            created_at BIGINT NOT NULL,
            updated_at BIGINT NOT NULL,
            last_error TEXT DEFAULT NULL
        )");
        $this->addSql('CREATE INDEX queue_outbox_available ON queue_outbox (status, available_at, id)');
        $this->addSql('CREATE INDEX queue_outbox_lease ON queue_outbox (status, lease_until, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE queue_outbox');
    }
}
