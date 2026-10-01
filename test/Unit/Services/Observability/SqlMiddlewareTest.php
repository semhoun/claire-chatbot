<?php

declare(strict_types=1);

namespace Test\Unit\Services\Observability;

use App\Services\Observability\SqlConnection;
use App\Services\Observability\SqlInstrumentation;
use App\Services\Observability\SqlMiddleware;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

final class SqlMiddlewareTest extends TestCase
{
    private InMemoryExporter $inMemoryExporter;

    private ScopeInterface $scope;

    protected function setUp(): void
    {
        $this->inMemoryExporter = new InMemoryExporter();
        $this->scope = Configurator::create()->withTracerProvider(
            new TracerProvider(new SimpleSpanProcessor($this->inMemoryExporter))
        )->activate();
    }

    protected function tearDown(): void
    {
        $this->scope->detach();
    }

    public function testSqlitePreparedReuseDirectCallsTransactionsAndPrivacy(): void
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new SqlMiddleware(new SqlInstrumentation(true))]);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $connection->executeStatement('CREATE TABLE test (value TEXT UNIQUE)');

        $statement = $connection->prepare('INSERT INTO test VALUES (?)');
        self::assertCount(1, $this->sqlSpans());
        $connection->beginTransaction();
        $statement->bindValue(1, 'bound-secret-one', ParameterType::STRING);
        self::assertSame(1, $statement->executeStatement());
        $statement->bindValue(1, 'bound-secret-two', ParameterType::STRING);
        self::assertSame(1, $statement->executeStatement());
        self::assertSame(2, $connection->fetchOne('SELECT COUNT(*) FROM test'));
        $connection->rollBack();
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM test'));
        self::assertCount(5, $this->sqlSpans());
        foreach ($this->sqlSpans() as $spanData) {
            self::assertSame('sqlite', $spanData->getAttributes()->get('db.system.name'));
            self::assertStringNotContainsString('bound-secret', json_encode($spanData->getAttributes()->toArray(), JSON_THROW_ON_ERROR));
            self::assertSame([], $spanData->getEvents());
        }

        $connection->beginTransaction();
        $statement->executeStatement();
        $connection->commit();
        try {
            $statement->executeStatement();
            self::fail('Unique constraint expected');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM test'));
        }

        self::assertCount(8, $this->sqlSpans());
    }

    public function testPrepareAndFetchAreOutsideSpanAndDriverExceptionIdentityIsPreserved(): void
    {
        $driver = $this->createMock(Connection::class);
        $statement = $this->createMock(Statement::class);
        $result = $this->createMock(Result::class);
        $driver->expects(self::once())->method('prepare')->willReturnCallback(function () use ($statement): Statement {
            self::assertCount(0, $this->sqlSpans());
            usleep(20000);
            return $statement;
        });
        $statement->expects(self::once())->method('execute')->willReturn($result);
        $result->expects(self::once())->method('fetchOne')->willReturnCallback(function (): int {
            self::assertCount(1, $this->sqlSpans());
            return 1;
        });
        $sqlConnection = new SqlConnection($driver, new SqlInstrumentation(true), 'sqlite');
        $stmt = $sqlConnection->prepare('SELECT 1');
        $beforeExecute = (int) (microtime(true) * 1e9);
        self::assertSame(1, $stmt->execute()->fetchOne());
        self::assertGreaterThanOrEqual($beforeExecute, $this->sqlSpans()[0]->getStartEpochNanos());
        $error = new \RuntimeException('driver-original');
        $driver->expects(self::once())->method('exec')->willThrowException($error);
        try {
            $sqlConnection->exec('DROP TABLE missing');
            self::fail('Expected driver exception');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($error, $runtimeException);
        }
    }

    /** @return list<\OpenTelemetry\SDK\Trace\SpanDataInterface> */
    private function sqlSpans(): array
    {
        return array_values(array_filter($this->inMemoryExporter->getSpans(),
            static fn ($span): bool => $span->getInstrumentationScope()->getName() === 'claire.sql'));
    }
}
