<?php

declare(strict_types=1);

namespace Migrations;

use App\BaseMigration;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;

final class Version20260311142237 extends BaseMigration
{
    public function getDescription(): string
    {
        return 'Add telegram_id column to user table with unique index';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $userTable = $this->quoteUserTable($platform);

        if ($this->isSqlitePlatform($platform)) {
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN telegram_id TEXT', $userTable));
            $this->addSql(sprintf('CREATE UNIQUE INDEX IF NOT EXISTS uk_telegram_id ON %s(telegram_id)', $userTable));

            return;
        }

        if ($this->isPostgreSqlPlatform($platform)) {
            $this->addSql(
                sprintf(
                    'ALTER TABLE %s ADD COLUMN telegram_id VARCHAR(64) DEFAULT NULL',
                    $userTable
                )
            );
            $this->addSql(sprintf('CREATE UNIQUE INDEX IF NOT EXISTS uk_telegram_id ON %s (telegram_id)', $userTable));

            return;
        }

        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN telegram_id VARCHAR(64) NULL', $userTable));
        $this->addSql(sprintf('CREATE UNIQUE INDEX uk_telegram_id ON %s(telegram_id)', $userTable));
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $userTable = $this->quoteUserTable($platform);

        if ($this->isSqlitePlatform($platform)) {
            return;
        }

        if ($this->isPostgreSqlPlatform($platform)) {
            $this->addSql('DROP INDEX IF EXISTS uk_telegram_id');
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN telegram_id', $userTable));

            return;
        }

        $this->addSql(sprintf('DROP INDEX uk_telegram_id ON %s', $userTable));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN telegram_id', $userTable));
    }

    private function quoteUserTable(AbstractPlatform $platform): string
    {
        return match (true) {
            $this->isMySqlPlatform($platform) => '`user`',
            default => '"user"',
        };
    }
}
