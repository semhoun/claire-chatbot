<?php

declare(strict_types=1);

namespace App\Test\Unit\Queue;

use App\Services\Queue\QueueRedisConnection;
use App\Services\Queue\RedisQueueBackend;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class QueueDispatchTest extends TestCase
{
    public function testEnqueueFailureIsPropagated(): void
    {
        $connection = $this->createMock(QueueRedisConnection::class);
        $connection->expects(self::once())->method('evaluate')
            ->willThrowException(new RuntimeException('Redis unavailable'));
        $backend = new RedisQueueBackend($connection, new Settings(['redis' => ['prefix' => 'test:']]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis unavailable');
        $backend->dispatch('ExampleJob', [], 'test');
    }

    public function testInvalidPayloadIsRejectedBeforeRedisWrites(): void
    {
        $connection = $this->createMock(QueueRedisConnection::class);
        $connection->expects(self::never())->method('evaluate');
        $backend = new RedisQueueBackend($connection, new Settings(['redis' => ['prefix' => 'test:']]));
        $this->expectException(RuntimeException::class);
        $backend->dispatch('ExampleJob', ['invalid' => "\xB1"], 'test');
    }
}
