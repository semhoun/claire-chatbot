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
        $channel = ChatStreamSubscriber::scope('user-1', 'sess-123');

        $redis->expects($this->once())
            ->method('brpop')
            ->with(['claire:sse:chat:' . $channel . ':queue'], 15)
            ->willReturn(['claire:sse:chat:' . $channel . ':queue', '{"msg":"hello"}']);

        $subscriber = new ChatStreamSubscriber($redis, $settings);
        $message = $subscriber->popMessage($channel, 15);

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
        $this->assertNull($subscriber->popMessage(ChatStreamSubscriber::scope('user-1', 'sess-123'), 15));
    }

    public function testUsersCannotConsumeEachOthersSessionChannel(): void
    {
        self::assertNotSame(
            ChatStreamSubscriber::scope('alice', 'same-session'),
            ChatStreamSubscriber::scope('bob', 'same-session'),
        );
        self::assertNotSame(
            ChatStreamSubscriber::scope('a:b', 'c'),
            ChatStreamSubscriber::scope('a', 'b:c'),
        );
        $this->expectException(\InvalidArgumentException::class);
        ChatStreamSubscriber::unScope('same-session');
    }

}
