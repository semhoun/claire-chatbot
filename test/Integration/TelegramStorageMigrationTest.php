<?php

declare(strict_types=1);

namespace App\Test\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Migrations\Version20260912130000;
use Migrations\Version20260912130001;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Real Doctrine execution; each server test creates and removes its own database. */
final class TelegramStorageMigrationTest extends TestCase
{
    private ?Connection $connection = null;
    private ?Connection $admin = null;
    private string $database = '';

    public static function drivers(): array
    {
        return [['pdo_sqlite'], ['pdo_mysql'], ['pdo_pgsql']];
    }

    #[DataProvider('drivers')]
    public function testMigrationsExecuteAndReverseWithLargePayloads(string $driver): void
    {
        $connection = $this->open($driver);
        $transactional = $driver !== 'pdo_mysql';
        $classes = [Version20260912130000::class, Version20260912130001::class];
        foreach ($classes as $class) {
            self::assertSame($transactional, (new $class($connection, new NullLogger()))->isTransactional());
        }
        $makeFactory = static fn (): DependencyFactory => DependencyFactory::fromConnection(new ConfigurationArray([
            'migrations' => $classes,
            'all_or_nothing' => $transactional,
            'transactional' => $transactional,
            'table_storage' => ['table_name' => 'db_version_storage_test'],
        ]), new ExistingConnection($connection), new NullLogger());
        $factory = $makeFactory();
        $factory->getMetadataStorage()->ensureInitialized();
        $versions = array_map(static fn (string $class): Version => new Version($class), $classes);
        $config = new MigratorConfiguration();
        $config->setAllOrNothing($transactional);
        $factory->getMigrator()->migrate(
            $factory->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::UP), $config,
        );

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM db_version_storage_test'));
        $schema = $connection->createSchemaManager();
        $journal = $schema->introspectTable('telegram_generation');
        self::assertCount(0, $journal->getForeignKeys());
        foreach (['user_id', 'thread_id', 'bot_id', 'update_id'] as $field) {
            self::assertTrue($journal->getColumn($field)->getNotnull());
        }
        self::assertContains(['delivered', 'compacted', 'completed_at', 'id'], array_map(
            static fn ($index): array => $index->getColumns(), $journal->getIndexes(),
        ));
        self::assertCount(0, $schema->introspectTable('queue_outbox')->getForeignKeys());

        $body = str_repeat('reply:', 12_000);
        $connection->insert('telegram_generation', [
            'id' => str_repeat('a', 64), 'user_id' => 'user', 'thread_id' => 'thread',
            'bot_id' => 'bot', 'update_id' => 'update:1', 'attempted' => 1, 'delivered' => 1,
            'compacted' => 0, 'response' => $body, 'deliveries' => '{}',
            'completed_at' => 1700000000, 'created_at' => 1700000000, 'updated_at' => 1700000000, 'revision' => 1,
        ]);
        $payload = json_encode(['response' => $body], JSON_THROW_ON_ERROR);
        $connection->insert('queue_outbox', [
            'id' => '00000000-0000-4000-8000-000000000001', 'event_key' => str_repeat('b', 64),
            'job_class' => 'ExampleJob', 'queue_name' => 'telegram', 'payload' => $payload,
            'status' => 'pending', 'attempts' => 0, 'available_at' => 1700000000,
            'created_at' => 1700000000, 'updated_at' => 1700000000,
        ]);
        self::assertSame($body, $connection->fetchOne('SELECT response FROM telegram_generation'));
        self::assertSame($payload, $connection->fetchOne('SELECT payload FROM queue_outbox'));

        $factory = $makeFactory();
        $factory->getMigrator()->migrate(
            $factory->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::DOWN), $config,
        );
        $tables = $connection->createSchemaManager()->listTableNames();
        self::assertNotContains('telegram_generation', $tables);
        self::assertNotContains('queue_outbox', $tables);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM db_version_storage_test'));
        $factory = $makeFactory();
        $factory->getMigrator()->migrate(
            $factory->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::UP), $config,
        );
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM telegram_generation'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM queue_outbox'));
    }

    private function open(string $driver): Connection
    {
        if ($driver === 'pdo_sqlite') {
            return $this->connection = DriverManager::getConnection(['driver' => $driver, 'memory' => true]);
        }
        $port = getenv($driver === 'pdo_mysql' ? 'CLAIRE_STORAGE_MYSQL_PORT' : 'CLAIRE_STORAGE_PGSQL_PORT');
        if ($port === false) {
            self::markTestSkipped('Requires an explicitly isolated SQL server port');
        }
        $params = [
            'driver' => $driver, 'host' => '127.0.0.1', 'port' => (int) $port,
            'user' => $driver === 'pdo_mysql' ? 'root' : 'postgres',
            'password' => 'claire-test-only', 'dbname' => 'claire_test',
        ];
        $this->admin = DriverManager::getConnection($params);
        $this->database = 'claire_storage_test_' . bin2hex(random_bytes(8));
        $this->admin->executeStatement('CREATE DATABASE ' . $this->admin->quoteIdentifier($this->database));
        $params['dbname'] = $this->database;
        return $this->connection = DriverManager::getConnection($params);
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
        if ($this->admin !== null) {
            self::assertStringStartsWith('claire_storage_test_', $this->database);
            $this->admin->executeStatement('DROP DATABASE IF EXISTS ' . $this->admin->quoteIdentifier($this->database));
            $this->admin->close();
        }
    }
}
