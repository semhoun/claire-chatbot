<?php

declare(strict_types=1);

namespace App\Services\Observability;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

final class SqlConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        Connection $connection,
        private readonly SqlInstrumentation $sqlInstrumentation,
        private readonly string $dbSystem,
    ) {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new class (parent::prepare($sql), $this->sqlInstrumentation, $this->dbSystem, $sql)
            extends AbstractStatementMiddleware {
            public function __construct(
                Statement $statement,
                private readonly SqlInstrumentation $sqlInstrumentation,
                private readonly string $dbSystem,
                private readonly string $sql,
            ) {
                parent::__construct($statement);
            }

            public function execute(): Result
            {
                return $this->sqlInstrumentation->measure(
                    $this->sql, $this->dbSystem, fn (): Result => parent::execute()
                );
            }
        };
    }

    public function query(string $sql): Result
    {
        return $this->sqlInstrumentation->measure($sql, $this->dbSystem, fn (): Result => parent::query($sql));
    }

    public function exec(string $sql): int|string
    {
        return $this->sqlInstrumentation->measure($sql, $this->dbSystem, fn (): int|string => parent::exec($sql));
    }
}
