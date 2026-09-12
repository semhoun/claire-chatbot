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
