<?php

declare(strict_types=1);

namespace App\Test\Unit\Queue;

use App\Services\Queue\QueueBackendInterface;
use App\Services\Queue\QueueDoer;
use App\Services\Queue\QueueMessage;
use App\Services\Queue\QueueWorker;
use App\Services\Queue\QueueWorkerOptions;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class QueueWorkerTest extends TestCase
{
    public function testSuccessAcknowledgesWithoutRelease(): void
    {
        $this->runJob(false);
    }

    public function testNonRetryableFailureDeadLettersImmediately(): void
    {
        $this->runLeasedFailure(new \App\Services\Queue\NonRetryableJobException('unsafe'), 'fail');
    }

    public function testChatContentionDefersWithoutNormalRelease(): void
    {
        $this->runLeasedFailure(new \App\Services\ChatGenerationBusyException('busy'), 'defer');
    }

    private function runLeasedFailure(\Throwable $error, string $transition): void
    {
        $backend = $this->createMock(\App\Services\Queue\LeasedQueueBackendInterface::class);
        $message = new QueueMessage('id', WorkerTestJob::class, [], 'test');
        $backend->method('reserveNextAvailable')->willReturn($message);
        $backend->method('withLease')->willThrowException($error);
        $backend->expects(self::once())->method($transition)->with($message);
        $backend->expects(self::never())->method('release');
        $backend->expects(self::never())->method('delete');
        $worker = new QueueWorker($backend, $this->createStub(ContainerInterface::class), new NullLogger());
        self::assertSame(1, $worker->run(new QueueWorkerOptions('test', 0, 1, 10), 'test-worker'));
    }

    public function testFailureReleasesWithoutAcknowledgement(): void
    {
        $this->runJob(true);
    }

    public function testReleaseFailureDoesNotTerminateWorker(): void
    {
        $this->runJob(true, true);
    }

    public function testInvalidJobIsReleasedNotDeleted(): void
    {
        $backend = $this->createMock(QueueBackendInterface::class);
        $message = new QueueMessage('invalid', 'MissingJobClass', [], 'test');
        $backend->method('reserveNextAvailable')->willReturn($message);
        $backend->expects(self::never())->method('delete');
        $backend->expects(self::once())->method('release')->with($message);
        $worker = new QueueWorker($backend, $this->createStub(ContainerInterface::class), new NullLogger());
        self::assertSame(1, $worker->run(new QueueWorkerOptions('test', 0, 1, 10), 'test-worker'));
    }

    public function testPeriodicMaintenancePurgesWhenSemanticFeatureIsDisabled(): void
    {
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE account (id VARCHAR(255) PRIMARY KEY)');
        $connection->insert('account', ['id' => 'synthetic-owner']);
        $migration = new \Migrations\Version20260930000200($connection, new NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $registry = new \App\Services\SemanticMemoryRegistry($connection);
        $registry->setEnabled('synthetic-owner', true);
        $registry->erase('synthetic-owner');
        $settings = new \App\Services\Settings([]);
        $memory = new \App\Services\SemanticMemoryService($connection, $registry, null, new NullLogger(),
            '/tmp/kilo/unused-worker-memory-purge', '', 0, false);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool =>
            $id === \App\Services\Settings::class);
        $container->method('get')->willReturnCallback(static fn (string $id): mixed => match ($id) {
            \App\Services\Settings::class => $settings,
            \Doctrine\DBAL\Connection::class => $connection,
            \App\Services\SemanticMemoryService::class => $memory,
            default => throw new RuntimeException('Unexpected dependency: ' . $id),
        });
        $backend = $this->createMock(\App\Services\Queue\LeasedQueueBackendInterface::class);
        $message = new QueueMessage('id', WorkerTestJob::class, ['fail' => false], 'test');
        $backend->method('reserveNextAvailable')->willReturn($message);
        $backend->method('withLease')->willReturnCallback(static fn ($message, callable $work): mixed => $work());
        $backend->expects(self::once())->method('delete')->with($message);
        $worker = new QueueWorker($backend, $container, new NullLogger());
        self::assertSame(1, $worker->run(new QueueWorkerOptions('test', 0, 1, 10), 'test-worker'));
        self::assertSame(0, (int) $connection->fetchOne(
            'SELECT purge_pending FROM semantic_memory_preference WHERE user_id = ?', ['synthetic-owner'],
        ));
    }

    private function runJob(bool $fail, bool $releaseFails = false): void
    {
        $backend = $this->createMock(QueueBackendInterface::class);
        $message = new QueueMessage('id', WorkerTestJob::class, ['fail' => $fail], 'test');
        $backend->method('reserveNextAvailable')->willReturn($message);
        $backend->expects($fail ? self::never() : self::once())->method('delete')->with($message);
        $release = $backend->expects($fail ? self::once() : self::never())->method('release')->with($message);
        if ($releaseFails) {
            $release->willThrowException(new RuntimeException('Redis unavailable'));
        }
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $worker = new QueueWorker($backend, $container, new NullLogger());
        self::assertSame(1, $worker->run(new QueueWorkerOptions('test', 0, 1, 10), 'test-worker'));
    }
}

final class WorkerTestJob implements QueueDoer
{
    public static function make(ContainerInterface $container): self
    {
        return new self();
    }

    public function handle(array $payload): void
    {
        if ($payload['fail']) {
            throw new RuntimeException('Job failed');
        }
    }
}
