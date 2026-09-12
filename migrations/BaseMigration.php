<?php

declare(strict_types=1);

namespace Migrations;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\Migrations\AbstractMigration;

abstract class BaseMigration extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return ! $this->isMySqlPlatform($this->connection->getDatabasePlatform());
    }

    protected function isMySqlPlatform(AbstractPlatform $platform): bool
    {
        $platformClass = strtolower($platform::class);

        return str_contains($platformClass, 'mysql')
            || str_contains($platformClass, 'mariadb');
    }

    protected function isPostgreSqlPlatform(AbstractPlatform $platform): bool
    {
        return str_contains(strtolower($platform::class), 'postgres')
            || str_contains(strtolower($platform::class), 'pgsql');
    }

    protected function isSqlitePlatform(AbstractPlatform $platform): bool
    {
        return str_contains(strtolower($platform::class), 'sqlite');
    }
}
