<?php

declare(strict_types=1);

namespace app;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260311142237 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add telegram_id column to user table with unique index';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            $this->addSql('ALTER TABLE user ADD COLUMN telegram_id TEXT');
            $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uk_telegram_id ON user(telegram_id)');
        } else {
            $this->addSql('ALTER TABLE user ADD COLUMN telegram_id VARCHAR(64) NULL');
            $this->addSql('CREATE UNIQUE INDEX uk_telegram_id ON user(telegram_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uk_telegram_id ON user');
        $this->addSql('ALTER TABLE user DROP COLUMN telegram_id');
    }
}
