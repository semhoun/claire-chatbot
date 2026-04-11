<?php

declare(strict_types=1);

namespace Migrations;

use App\BaseMigration;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;

final class Version20251122210810 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Create user table';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $userTable = $this->quoteUserTable($platform);

        if ($this->isSqlitePlatform($platform)) {
            $this->addSql(
                <<<EOT
CREATE TABLE IF NOT EXISTS {$userTable} (
    id TEXT NOT NULL,
    first_name TEXT,
    last_name TEXT,
    email TEXT,
    params TEXT,
    picture BLOB
);
EOT
            );
        } elseif ($this->isPostgreSqlPlatform($platform)) {
            $this->addSql(
                <<<EOT
CREATE TABLE IF NOT EXISTS {$userTable} (
    id VARCHAR(64) NOT NULL,
    first_name VARCHAR(128),
    last_name VARCHAR(128),
    email VARCHAR(255),
    params TEXT,
    picture BYTEA,
    PRIMARY KEY (id)
);
EOT
            );

            return;
        }

        $this->addSql(
            <<<EOT
CREATE TABLE IF NOT EXISTS {$userTable} (
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

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->addSql(sprintf('DROP TABLE %s', $this->quoteUserTable($platform)));
    }

    private function quoteUserTable(AbstractPlatform $platform): string
    {
        return match (true) {
            $this->isMySqlPlatform($platform) => '`user`',
            default => '"user"',
        };
    }

}
