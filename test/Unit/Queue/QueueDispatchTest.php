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
    public function testMalformedWebPayloadNeverTouchesRedisOrSql(): void
    {
        $redis = $this->createMock(QueueRedisConnection::class);
        $redis->expects(self::never())->method('evaluate');
        $sql = $this->createMock(\Doctrine\DBAL\Connection::class);
        $sql->expects(self::never())->method('getNativeConnection');
        $backend = new RedisQueueBackend($redis, new Settings(['redis' => ['prefix' => 'test:']]), $sql);
        $valid = ['threadId' => 'thread', 'sessionId' => 'tab', 'messageId' => 'message-test',
            'message' => 'hello', 'session' => [\App\Services\Auth::USERID => 'user']];
        foreach ([['session' => 'invalid'], ['messageId' => ''], ['message' => []],
            ['threadId' => ''], ['attachments' => ['fileIds' => 'invalid']]] as $invalid) {
            try {
                $backend->dispatch(\App\Job\Web\NewMessageJob::class, array_replace($valid, $invalid), 'test');
                self::fail('Malformed payload accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testEnqueueFailureIsPropagated(): void
    {
        $connection = $this->createMock(QueueRedisConnection::class);
        $connection->expects(self::once())->method('evaluate')
            ->willThrowException(new RuntimeException('Redis unavailable'));
        $backend = new RedisQueueBackend($connection, new Settings(['redis' => ['prefix' => 'test:']]),
            $this->createStub(\Doctrine\DBAL\Connection::class));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis unavailable');
        $backend->dispatch('ExampleJob', [], 'test');
    }

    public function testInvalidPayloadIsRejectedBeforeRedisWrites(): void
    {
        $connection = $this->createMock(QueueRedisConnection::class);
        $connection->expects(self::never())->method('evaluate');
        $backend = new RedisQueueBackend($connection, new Settings(['redis' => ['prefix' => 'test:']]),
            $this->createStub(\Doctrine\DBAL\Connection::class));
        $this->expectException(RuntimeException::class);
        $backend->dispatch('ExampleJob', ['invalid' => "\xB1"], 'test');
    }
}
