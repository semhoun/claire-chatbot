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
    public function testPublishSucceedsWhenConsumerRemovesQueueBeforeExpiration(): void
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:'], 'sse' => ['queue_ttl' => 60]]);
        $redis = $this->createMock(RedisClient::class);
        $subscriber = new ChatStreamSubscriber($redis, $settings);
        $channel = ChatStreamSubscriber::scope('user-1', 'tab');
        $redis->expects(self::once())->method('lpush')->willReturn(1);
        $redis->expects(self::once())->method('expire')
            ->with($subscriber->channel($channel) . ':queue', 60)->willReturn(false);

        new ChatStreamPublisher($redis, $subscriber, $settings)->publish($channel, 'chat.snapshot', [
            'threadId' => 'thread-1',
        ]);
    }

    public function testPublishStillFailsWhenPushFails(): void
    {
        $settings = new Settings(['redis' => ['prefix' => 'test:'], 'sse' => ['queue_ttl' => 60]]);
        $redis = $this->createMock(RedisClient::class);
        $subscriber = new ChatStreamSubscriber($redis, $settings);
        $redis->expects(self::once())->method('lpush')->willReturn(false);
        $redis->expects(self::never())->method('expire');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot publish chat stream event');
        new ChatStreamPublisher($redis, $subscriber, $settings)->publish(
            ChatStreamSubscriber::scope('user-1', 'tab'), 'chat.snapshot', ['threadId' => 'thread-1'],
        );
    }

    public function testPublishUsesThreadScopedChannelAndEnvelope(): void
    {
        $settings = new Settings([
            'redis' => [
                'prefix' => 'claire:',
            ],
            'sse' => [
                'queue_ttl' => 60,
            ],
        ]);
        $redis = $this->createMock(RedisClient::class);
        $subscriber = new ChatStreamSubscriber($redis, $settings);
        $channel = ChatStreamSubscriber::scope('user-1', 'thread-1');

        $redis->expects($this->once())
            ->method('expire')
            ->with(
                $this->equalTo('claire:sse:chat:' . $channel . ':queue'),
                60
            )->willReturn(true);

        $redis->expects($this->once())
            ->method('lpush')
            ->with(
                $this->equalTo('claire:sse:chat:' . $channel . ':queue'),
                $this->callback(static function (array $payloadArr): bool {
                    $data = json_decode($payloadArr[0], true);

                    return is_array($data)
                        && $data['version'] === 1
                        && ! isset($data['seq'])
                        && $data['event'] === 'chat.snapshot'
                        && $data['threadId'] === 'thread-1'
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
