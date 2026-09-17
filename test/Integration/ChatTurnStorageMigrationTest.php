<?php

declare(strict_types=1);

namespace App\Test\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Migrations\Version20260917000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Real Doctrine runner; server fixtures live in dedicated, disposable databases. */
final class ChatTurnStorageMigrationTest extends TestCase
{
    private ?Connection $connection = null;
    private ?Connection $admin = null;
    private string $database = '';

    public static function drivers(): array
    {
        return ['sqlite' => ['pdo_sqlite'], 'mariadb' => ['pdo_mysql'], 'postgresql' => ['pdo_pgsql']];
    }

    #[DataProvider('drivers')]
    public function testRunnerPreservesExistingHistoryAndStoresLargeCheckpoints(string $driver): void
    {
        $connection = $this->open($driver);
        $history = new Table('chat_history');
        $history->addColumn('id', 'integer');
        $history->addColumn('user_id', 'string', ['length' => 64]);
        $history->addColumn('thread_id', 'string', ['length' => 128]);
        $history->addColumn('messages', 'text');
        $history->addColumn('display_messages', 'text');
        $history->addColumn('display_messages_count', 'integer');
        foreach (['title', 'summary'] as $column) {
            $history->addColumn($column, 'text', ['notnull' => false]);
        }
        foreach (['created_at', 'updated_at'] as $column) {
            $history->addColumn($column, 'datetime', ['notnull' => false]);
        }
        $history->setPrimaryKey(['id']);
        $history->addUniqueIndex(['thread_id'], 'uk_thread_id');
        $history->addIndex(['user_id'], 'idx_user_id');
        $connection->createSchemaManager()->createTable($history);
        $connection->insert('chat_history', [
            'id' => 1, 'user_id' => 'alice', 'thread_id' => 'existing-thread',
            'messages' => '[ {"role":"user","content":"Previous question"} ]',
            'display_messages' => '[{"role":"assistant","content":"Previous answer"}]',
            'display_messages_count' => 1, 'title' => '', 'summary' => 'Existing summary',
            'created_at' => '2020-01-02 03:04:05', 'updated_at' => '2021-02-03 04:05:06',
        ]);
        $connection->insert('chat_history', [
            'id' => 2, 'user_id' => 'bob', 'thread_id' => 'empty-thread',
            'messages' => '[]', 'display_messages' => '[]', 'display_messages_count' => 0,
            'title' => null, 'summary' => null, 'created_at' => null, 'updated_at' => null,
        ]);
        $before = $connection->fetchAllAssociative('SELECT * FROM chat_history ORDER BY id');
        $columns = implode(', ', array_keys($before[0]));
        $transactional = $driver !== 'pdo_mysql';
        self::assertSame($transactional,
            new Version20260917000000($connection, new NullLogger())->isTransactional());
        $makeFactory = static fn (): DependencyFactory => DependencyFactory::fromConnection(new ConfigurationArray([
            'migrations' => [Version20260917000000::class],
            'all_or_nothing' => $transactional,
            'transactional' => $transactional,
            'table_storage' => ['table_name' => 'db_version_storage_test'],
        ]), new ExistingConnection($connection), new NullLogger());
        $factory = $makeFactory();
        $factory->getMetadataStorage()->ensureInitialized();
        $versions = [new Version(Version20260917000000::class)];
        $config = new MigratorConfiguration();
        $config->setAllOrNothing($transactional);
        $factory->getMigrator()->migrate(
            $factory->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::UP), $config,
        );

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM db_version_storage_test'));
        self::assertSame($before, $connection->fetchAllAssociative('SELECT ' . $columns
            . ' FROM chat_history ORDER BY id'));
        self::assertSame(2, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM chat_history WHERE revision = 0 AND current_turn_id IS NULL',
        ));
        $schema = $connection->createSchemaManager();
        $turn = $schema->introspectTable('chat_turn');
        self::assertFalse($turn->getColumn('checkpoint')->getNotnull());
        self::assertSame(['id'], $turn->getPrimaryKey()->getColumns());
        $indexes = array_map(static fn ($index): array => $index->getColumns(), $turn->getIndexes());
        self::assertContains(['user_id', 'thread_id'], $indexes);
        self::assertContains(['status', 'created_at', 'id'], $indexes);
        self::assertEqualsCanonicalizing(['chat_history', 'chat_turn', 'db_version_storage_test'],
            $schema->listTableNames());
        if ($driver === 'pdo_mysql') {
            self::assertSame('longtext', strtolower((string) $connection->fetchOne(
                "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
                . " AND TABLE_NAME = 'chat_turn' AND COLUMN_NAME = 'checkpoint'",
            )));
        }

        $checkpoint = json_encode(['version' => 1, 'history' => [
            'messages' => str_repeat('context:', 12_000),
            'display_messages' => str_repeat('display:', 12_000),
            'display_messages_count' => 1, 'title' => '', 'summary' => null,
            'updated_at' => '2021-02-03 04:05:06',
        ]], JSON_THROW_ON_ERROR);
        self::assertGreaterThan(65_535, strlen($checkpoint));
        $connection->insert('chat_turn', [
            'id' => 'turn', 'user_id' => 'alice', 'thread_id' => 'existing-thread', 'channel' => 'web',
            'generation_id' => 'generation', 'submission_id' => 'submission', 'status' => 'running',
            'checkpoint' => $checkpoint, 'notification' => '{}', 'history_revision' => 1,
            'revision' => 1, 'created_at' => 1700000000, 'updated_at' => 1700000000,
        ]);
        self::assertSame($checkpoint, $connection->fetchOne('SELECT checkpoint FROM chat_turn'));
        $connection->update('chat_history', ['revision' => 17, 'current_turn_id' => 'turn'], ['id' => 1]);

        $factory = $makeFactory();
        $factory->getMigrator()->migrate(
            $factory->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::DOWN), $config,
        );
        self::assertEqualsCanonicalizing(['chat_history', 'db_version_storage_test'],
            $connection->createSchemaManager()->listTableNames());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM db_version_storage_test'));
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM chat_history ORDER BY id'));
        $restored = $connection->createSchemaManager()->introspectTable('chat_history');
        self::assertFalse($restored->hasColumn('revision'));
        self::assertFalse($restored->hasColumn('current_turn_id'));
        self::assertSame(['id'], $restored->getPrimaryKey()->getColumns());
        self::assertSame(['thread_id'], $restored->getIndex('uk_thread_id')->getColumns());
        self::assertTrue($restored->getIndex('uk_thread_id')->isUnique());
        self::assertSame(['user_id'], $restored->getIndex('idx_user_id')->getColumns());

        $factory = $makeFactory();
        $factory->getMigrator()->migrate(
            $factory->getMigrationPlanCalculator()->getPlanForVersions($versions, Direction::UP), $config,
        );
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM db_version_storage_test'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM chat_turn'));
        self::assertSame(2, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM chat_history WHERE revision = 0 AND current_turn_id IS NULL',
        ));
        self::assertSame($before, $connection->fetchAllAssociative('SELECT ' . $columns
            . ' FROM chat_history ORDER BY id'));
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
        $this->database = 'claire_turn_storage_test_' . bin2hex(random_bytes(8));
        $this->admin->executeStatement('CREATE DATABASE ' . $this->admin->quoteIdentifier($this->database));
        $params['dbname'] = $this->database;
        return $this->connection = DriverManager::getConnection($params);
    }

    protected function tearDown(): void
    {
        $this->connection?->close();
        if ($this->admin !== null) {
            self::assertStringStartsWith('claire_turn_storage_test_', $this->database);
            $this->admin->executeStatement('DROP DATABASE IF EXISTS ' . $this->admin->quoteIdentifier($this->database));
            $this->admin->close();
        }
    }
}
