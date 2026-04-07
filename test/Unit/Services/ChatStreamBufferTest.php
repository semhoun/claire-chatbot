<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\ChatStreamBuffer;
use App\Services\RedisClientInterface;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

final class ChatStreamBufferTest extends TestCase
{
    public function testReadSinceReturnsStringList(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $settings = new Settings([
            'redis' => [
                'prefix' => 'claire:',
            ],
        ]);

        $redis->expects($this->once())
            ->method('eval')
            ->willReturn(['{"event":"chat.snapshot"}', '{"event":"message.assistant.delta"}']);

        $buffer = new ChatStreamBuffer($redis, $settings);

        $this->assertSame(
            ['{"event":"chat.snapshot"}', '{"event":"message.assistant.delta"}'],
            $buffer->readSince('thread-1', 0),
        );
    }
}
