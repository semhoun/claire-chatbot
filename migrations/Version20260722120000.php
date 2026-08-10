<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260722120000 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Create evolving long-term memory storage';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($this->isSqlitePlatform($platform)) {
            $this->addSql(
                'CREATE TABLE long_term_memory ('
                . 'user_id TEXT PRIMARY KEY NOT NULL, content CLOB NOT NULL, '
                . 'updated_at DATETIME NOT NULL, FOREIGN KEY (user_id) '
                . 'REFERENCES account(id) ON DELETE CASCADE)'
            );
            return;
        }

        if ($this->isPostgreSqlPlatform($platform)) {
            $this->addSql(
                'CREATE TABLE long_term_memory ('
                . 'user_id VARCHAR(64) PRIMARY KEY NOT NULL, content TEXT NOT NULL, '
                . 'updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
                . 'CONSTRAINT fk_long_term_memory_user FOREIGN KEY (user_id) '
                . 'REFERENCES account(id) ON DELETE CASCADE)'
            );
            return;
        }

        $this->addSql(
            'CREATE TABLE long_term_memory ('
            . 'user_id VARCHAR(64) PRIMARY KEY NOT NULL, content LONGTEXT NOT NULL, '
            . 'updated_at DATETIME NOT NULL, '
            . 'CONSTRAINT fk_long_term_memory_user FOREIGN KEY (user_id) '
            . 'REFERENCES account(id) ON DELETE CASCADE)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS long_term_memory');
    }
}
