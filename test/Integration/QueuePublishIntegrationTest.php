<?php

declare(strict_types=1);

namespace App\Test\Integration;

use App\Console\QueuePublishCommand;
use App\Services\Queue\QueueRedisConnection;
use App\Services\Queue\RedisQueueBackend;
use App\Services\Queue\SqlOutboxQueueBackend;
use App\Services\Queue\SqlQueueOutbox;
use App\Services\Settings;
use App\Services\TelegramService;
use App\Test\Support\TelegramSqlSchema;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

require_once dirname(__DIR__) . '/Support/TelegramSqlSchema.php';

final class QueuePublishIntegrationTest extends TestCase
{
    public function testCliPublishesPersistedReceiptAfterBrokerFailureWithoutExecutingIt(): void
    {
        $port = getenv('OUTBOX_TEST_REDIS_PORT');
        if ($port === false || ! extension_loaded('redis')) {
            self::markTestSkipped('Requires explicitly isolated OUTBOX_TEST_REDIS_PORT and ext-redis');
        }
        $prefix = 'publish-cli-test:' . bin2hex(random_bytes(12)) . ':';
        $settings = new Settings([
            'redis' => ['host' => '127.0.0.1', 'port' => (int) $port, 'database' => 0,
                'password' => null, 'timeout' => 2, 'prefix' => $prefix],
            'queue' => ['retryDelaySeconds' => 1, 'maxRetryDelaySeconds' => 2],
            'telegram' => ['bot_token' => '123:test-only'],
        ]);
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        TelegramSqlSchema::create($db);
        $outbox = new SqlQueueOutbox($db, $settings);
        $failedRedis = new class ($settings) extends QueueRedisConnection {
            public function evaluate(string $script, array $arguments, int $numberOfKeys): mixed
            {
                throw new \RuntimeException('PRIVATE BROKER CREDENTIAL');
            }
        };
        $offline = new SqlOutboxQueueBackend(new RedisQueueBackend($failedRedis, $settings, $db), $outbox);
        $jobId = $offline->dispatch(TelegramService::class, [
            'update_json' => json_encode(['update_id' => 71, 'message' => ['text' => 'PRIVATE INPUT']], JSON_THROW_ON_ERROR),
        ], 'telegram');
        $row = $db->fetchAssociative('SELECT * FROM queue_outbox WHERE id = ?', [$jobId]);
        self::assertSame('pending', $row['status']);
        self::assertSame(0, (int) $row['attempts']);
        self::assertStringNotContainsString('CREDENTIAL', $row['last_error']);
        $db->update('queue_outbox', ['available_at' => 0], ['id' => $jobId]);

        $redis = new \Redis();
        self::assertTrue($redis->connect('127.0.0.1', (int) $port, 2));
        try {
            $transport = new RedisQueueBackend(new QueueRedisConnection($settings), $settings, $db);
            $online = new SqlOutboxQueueBackend($transport, $outbox);
            $container = $this->createStub(ContainerInterface::class);
            $container->method('get')->willReturn($online);
            $tester = new CommandTester(new QueuePublishCommand($container));
            self::assertSame(0, $tester->execute(['--queue' => 'telegram', '--limit' => '1']));
            self::assertSame(['queue' => 'telegram', 'selected' => 1, 'published' => 1, 'failed' => 0, 'skipped' => 0],
                json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('PRIVATE', $tester->getDisplay());
            self::assertNull($transport->reserveNextAvailable('telegram', 0));
            $job = $online->reserveNextAvailable('telegram', 0);
            self::assertNotNull($job);
            self::assertSame($jobId, $job->id);
            self::assertSame($jobId, $job->metadata['outbox_id']);
            self::assertSame('sql-outbox:telegram', $job->queueName);
            self::assertSame(0, (int) $db->fetchOne('SELECT attempts FROM queue_outbox WHERE id = ?', [$jobId]));
            $online->delete($job);
        } finally {
            $cursor = null;
            $keys = [];
            do {
                $batch = $redis->scan($cursor, $prefix . '*', 100);
                if ($batch !== false) array_push($keys, ...$batch);
            } while ($cursor !== 0);
            if ($keys !== []) $redis->del($keys);
            $redis->close();
            $db->close();
        }
    }
}
