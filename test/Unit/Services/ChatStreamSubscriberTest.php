<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\ChatStreamSubscriber;
use App\Services\RedisClient;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

final class ChatStreamSubscriberTest extends TestCase
{
    public function testPopMessageCallsBrpopOnCorrectChannel(): void
    {
        $settings = new Settings([
            'redis' => [
                'prefix' => 'claire:',
            ],
        ]);
        $redis = $this->createMock(RedisClient::class);

        $redis->expects($this->once())
            ->method('brpop')
            ->with(['claire:sse:chat:sess-123:queue'], 15)
            ->willReturn(['claire:sse:chat:sess-123:queue', '{"msg":"hello"}']);

        $subscriber = new ChatStreamSubscriber($redis, $settings);
        $message = $subscriber->popMessage('sess-123', 15);

        $this->assertSame('{"msg":"hello"}', $message);
    }

    public function testPopMessageReturnsNullOnTimeout(): void
    {
        $settings = new Settings(['redis' => ['prefix' => 'claire:']]);
        $redis = $this->createMock(RedisClient::class);

        $redis->expects($this->once())
            ->method('brpop')
            ->willReturn(null);

        $subscriber = new ChatStreamSubscriber($redis, $settings);
        $this->assertNull($subscriber->popMessage('sess-123', 15));
    }

}
