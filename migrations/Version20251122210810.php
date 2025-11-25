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
  first_name TEXT NOT NULL,
  last_name TEXT NOT NULL,
  email TEXT NOT NULL,
  picture BLOB
);
EOT
            );
        } else {
            $this->addSql(
                <<<EOT
CREATE TABLE IF NOT EXISTS user (
    id VARCHAR(64) NOT NULL,
    first_name VARCHAR(128) NOT NULL,
    last_name VARCHAR(128) NOT NULL,
    email VARCHAR(255) NOT NULL,
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
