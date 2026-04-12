<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\ChatStreamSubscriber;
use App\Services\RedisClientInterface;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

final class ChatStreamSubscriberTest extends TestCase
{
    public function testSubscribeUsesSessionScopedChannel(): void
    {
        $settings = new Settings([
            'redis' => [
                'prefix' => 'claire:',
            ],
        ]);
        $redis = $this->createMock(RedisClientInterface::class);

        $redis->expects($this->once())
            ->method('subscribeWithHeartbeat')
            ->with(
                ['claire:sse:chat:sess-abc123'],
                $this->callback(static function (callable $callback): bool {
                    $callback('claire:sse:chat:sess-abc123', '{"event":"chat.snapshot"}');

                    return true;
                }),
                15.0,
                $this->isCallable(),
            );

        $subscriber = new ChatStreamSubscriber($redis, $settings);
        $receivedMessages = [];

        $subscriber->subscribe('sess-abc123', static function (string $message) use (&$receivedMessages): void {
            $receivedMessages[] = $message;
        });

        $this->assertSame(['{"event":"chat.snapshot"}'], $receivedMessages);
    }
}
