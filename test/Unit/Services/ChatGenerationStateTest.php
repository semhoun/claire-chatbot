<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\ChatGenerationState;
use App\Services\RedisClient;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

final class ChatGenerationStateTest extends TestCase
{
    public function testPersistentQueuedRunningAndTerminalSnapshotsAreUserScoped(): void
    {
        $storage = [];
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hset')->willReturnCallback(static function (string $key, array $value) use (&$storage): int {
            $storage[$key] = $value;
            return 1;
        });
        $redis->method('hgetall')->willReturnCallback(static function (string $key) use (&$storage): array {
            return $storage[$key] ?? [];
        });
        $settings = new Settings(['redis' => ['prefix' => 'test:']]);
        $state = new ChatGenerationState($redis, $settings);
        foreach (['queued', 'running', 'done', 'error', 'deleted'] as $status) {
            $state->set('alice', 'thread', 'message', $status, $status !== 'queued');
            $responding = in_array($status, ['queued', 'running'], true);
            self::assertSame([
                'responding' => $responding,
                'activeMessageId' => $responding ? 'message' : null,
            ], new ChatGenerationState($redis, $settings)->snapshot('alice', 'thread'));
            self::assertSame(['responding' => false, 'activeMessageId' => null], $state->snapshot('bob', 'thread'));
            foreach (['chat.assistant.start', 'chat.assistant.placeholder', 'chat.assistant.update',
                'chat.tool.update', 'chat.assistant.done', 'chat.error'] as $event) {
                $accepted = match ($event) {
                    'chat.assistant.done' => $status === 'done',
                    'chat.error' => $status === 'error',
                    default => $responding,
                };
                self::assertSame($accepted, $state->acceptsEvent('alice', 'thread', $event, 'message'));
                self::assertFalse($state->acceptsEvent('alice', 'thread', $event, 'older-message'));
                self::assertFalse($state->acceptsEvent('bob', 'thread', $event, 'message'));
            }
        }
    }

    public function testSnapshotReloadsHistoryIfGenerationCompletesDuringRead(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $running = ['status' => 'running', 'messageId' => 'message'];
        $done = ['status' => 'done', 'messageId' => 'message'];
        $redis->expects(self::exactly(4))->method('hgetall')->willReturn($running, $done, $done, $done);
        $reads = 0;
        $snapshot = new ChatGenerationState($redis, new Settings(['redis' => ['prefix' => 'test:']]))
            ->capture('alice', 'thread', static function () use (&$reads): array {
                return ['html' => ++$reads === 1 ? 'partial' : 'complete'];
            });
        self::assertSame(['html' => 'complete', 'responding' => false, 'activeMessageId' => null], $snapshot);
    }
}
