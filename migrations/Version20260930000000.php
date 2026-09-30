<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260930000000 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Add canonical archived chat transcript alongside existing projections';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(! $this->isMySqlPlatform($platform)
            && ! $this->isPostgreSqlPlatform($platform)
            && ! $this->isSqlitePlatform($platform), 'Unsupported history database');
        $text = $this->isMySqlPlatform($platform) ? 'LONGTEXT' : 'TEXT';
        $this->addSql('ALTER TABLE chat_history ADD COLUMN stored_messages ' . $text . ' DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Canonical archived history must not be discarded');
    }
}
