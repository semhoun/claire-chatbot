<?php

declare(strict_types=1);

namespace App;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\Migrations\AbstractMigration;

abstract class BaseMigration extends AbstractMigration
{
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
