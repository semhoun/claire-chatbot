<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamBuffer;
use App\Services\RedisClientInterface;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

final class ChatStreamPublisherTest extends TestCase
{
    public function testPublishUsesThreadScopedChannelAndEnvelope(): void
    {
        $redis = $this->createMock(RedisClientInterface::class);
        $buffer = $this->createMock(ChatStreamBuffer::class);
        $settings = new Settings([
            'redis' => [
                'prefix' => 'claire:',
            ],
        ]);

        $buffer->expects($this->once())
            ->method('push')
            ->with('thread-1', $this->isType('string'));

        $redis->expects($this->once())
            ->method('publish')
            ->with(
                'claire:sse:chat:thread-1',
                $this->callback(static function (string $payload): bool {
                    $data = json_decode($payload, true);

                    return is_array($data)
                        && $data['version'] === 1
                        && $data['event'] === 'chat.snapshot'
                        && $data['chatId'] === 'thread-1'
                        && $data['payload']['messagesHtml'] === '<div>ok</div>';
                })
            )
            ->willReturn(1);

        $publisher = new ChatStreamPublisher($redis, $buffer, $settings);
        $publisher->publish('thread-1', 'chat.snapshot', [
            'messagesHtml' => '<div>ok</div>',
        ]);
    }
}
