<?php

declare(strict_types=1);

namespace App\Test\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Migrations\Version20260912130000;
use Psr\Log\NullLogger;

/** Uses the actual migration SQL, only on connections explicitly supplied by tests. */
final class TelegramSqlSchema
{
    public static function create(Connection $connection): void
    {
        self::migrate($connection, Version20260912130000::class);
    }

    public static function drop(Connection $connection): void
    {
        self::migrate($connection, Version20260912130000::class, false);
    }

    /** @param class-string<AbstractMigration> $class */
    public static function migrate(Connection $connection, string $class, bool $up = true): void
    {
        $migration = new $class($connection, new NullLogger());
        if ($up) {
            $migration->up(new Schema());
        } else {
            $migration->down(new Schema());
        }
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
