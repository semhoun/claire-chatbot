<?php

declare(strict_types=1);

namespace App\Services\Observability;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

final readonly class SqlMiddleware implements Middleware
{
    public function __construct(private SqlInstrumentation $sqlInstrumentation)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class ($driver, $this->sqlInstrumentation) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly SqlInstrumentation $sqlInstrumentation)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                return new SqlConnection(
                    parent::connect($params), $this->sqlInstrumentation, $params['driver'] ?? 'other_sql'
                );
            }
        };
    }
}
