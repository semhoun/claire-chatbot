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

        $redis->expects($this->once())
            ->method('expire')
            ->with(
                $this->equalTo('claire:sse:chat:thread-1:queue'),
                60
            );

        $redis->expects($this->once())
            ->method('rpush')
            ->with(
                $this->equalTo('claire:sse:chat:thread-1:queue'),
                $this->callback(static function (array $payloadArr): bool {
                    $data = json_decode($payloadArr[0], true);

                    return is_array($data)
                        && $data['version'] === 1
                        && ! isset($data['seq'])
                        && $data['event'] === 'chat.snapshot'
                        && $data['threadId'] === 'thread-1'
                        && $data['payload']['messagesHtml'] === '<div>ok</div>';
                })
            );

        $publisher = new ChatStreamPublisher($redis, $subscriber, $settings);
        $publisher->publish('thread-1', 'chat.snapshot', [
            'threadId' => 'thread-1',
            'messagesHtml' => '<div>ok</div>',
        ]);
    }
}
