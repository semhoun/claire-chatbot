<?php

declare(strict_types=1);

namespace app;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251122210810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user table';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            $this->addSql(
                <<<EOT
CREATE TABLE IF NOT EXISTS user (
    id TEXT NOT NULL,
    first_name TEXT,
    last_name TEXT,
    email TEXT,
    params TEXT,
    picture BLOB
);
EOT
            );
        } else {
            $this->addSql(
                <<<EOT
CREATE TABLE IF NOT EXISTS user (
    id VARCHAR(64) NOT NULL,
    first_name VARCHAR(128),
    last_name VARCHAR(128),
    email VARCHAR(255),
    params TEXT,
    picture BLOB,
    PRIMARY KEY (id)
);
EOT
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user');
    }
}
