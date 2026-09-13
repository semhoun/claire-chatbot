<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\RedisClient;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

final class ChatStreamPublisherTest extends TestCase
{
    public function testPublishSucceedsWithZeroListenersWithoutPersistingEvents(): void
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:']]);
        $redis = $this->createMock(RedisClient::class);
        $subscriber = new ChatStreamSubscriber($settings);
        $channel = ChatStreamSubscriber::scope('user-1', 'tab');
        $redis->expects(self::once())->method('publish')->willReturn(0);
        $redis->expects(self::never())->method('lpush');
        $redis->expects(self::never())->method('expire');

        new ChatStreamPublisher($redis, $subscriber, $settings)->publish($channel, 'chat.snapshot', [
            'threadId' => 'thread-1',
        ]);
    }

    public function testPublishFailsWhenRedisPublicationFails(): void
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:']]);
        $redis = $this->createMock(RedisClient::class);
        $subscriber = new ChatStreamSubscriber($settings);
        $redis->expects(self::once())->method('publish')->willReturn(false);
        $redis->expects(self::never())->method('expire');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot publish chat stream event');
        new ChatStreamPublisher($redis, $subscriber, $settings)->publish(
            ChatStreamSubscriber::scope('user-1', 'tab'), 'chat.snapshot', ['threadId' => 'thread-1'],
        );
    }

    public function testPublishUsesUserTabScopedChannelAndEnvelope(): void
    {
        $settings = new Settings([
            'redis' => [
                'prefix' => 'claire:',
            ],
        ]);
        $redis = $this->createMock(RedisClient::class);
        $subscriber = new ChatStreamSubscriber($settings);
        $channel = ChatStreamSubscriber::scope('user-1', 'thread-1');

        $redis->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo('claire:sse:chat:' . $channel),
                $this->callback(static function (string $message): bool {
                    $data = json_decode($message, true, flags: JSON_THROW_ON_ERROR);

                    return is_array($data)
                        && $data['version'] === 1
                        && ! isset($data['seq'])
                        && $data['event'] === 'chat.snapshot'
                        && $data['threadId'] === 'thread-1'
                        && $data['payload']['sessionId'] === 'thread-1'
                        && $data['payload']['messagesHtml'] === '<div>ok</div>';
                })
            )->willReturn(1);

        $publisher = new ChatStreamPublisher($redis, $subscriber, $settings);
        $publisher->publish($channel, 'chat.snapshot', [
            'threadId' => 'thread-1',
            'messagesHtml' => '<div>ok</div>',
        ]);
    }
}
