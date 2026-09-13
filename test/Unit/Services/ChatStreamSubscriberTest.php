<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\ChatStreamSubscriber;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

final class ChatStreamSubscriberTest extends TestCase
{
    public function testStaticAndInstanceChannelsMatch(): void
    {
        $settings = new Settings([
            'redis' => [
                'prefix' => 'claire:',
            ],
        ]);
        $channel = ChatStreamSubscriber::scope('user-1', 'sess-123');

        $subscriber = new ChatStreamSubscriber($settings);
        self::assertSame('claire:sse:chat:' . $channel, $subscriber->channel($channel));
        self::assertSame($subscriber->channel($channel), ChatStreamSubscriber::channelName('claire:', $channel));
        self::assertSame('sess-123', ChatStreamSubscriber::unScope($channel));
    }

    public function testStaticChannelRejectsUnscopedInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ChatStreamSubscriber::channelName('claire:', 'sess-123');
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
