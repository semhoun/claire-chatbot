<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\ChatGenerationState;
use App\Services\RedisClient;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

final class ChatGenerationStateTest extends TestCase
{
    public function testStaticKeyAndFilteringRules(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $key = ChatGenerationState::stateKey('test:', 'alice', 'thread');
        $redis->expects(self::once())->method('hgetall')->with($key)->willReturn([]);
        $state = new ChatGenerationState($redis, new Settings(['redis' => ['prefix' => 'test:']]));
        self::assertSame($key, $state->key('alice', 'thread'));
        self::assertSame([], $state->get('alice', 'thread'));
        self::assertNotSame($key, ChatGenerationState::stateKey('test:', 'bob', 'thread'));
        self::assertNotSame(ChatGenerationState::stateKey('', 'a:b', 'c'),
            ChatGenerationState::stateKey('', 'a', 'b:c'));
        self::assertFalse(ChatGenerationState::acceptsState([], 'chat.assistant.start', ''));
        self::assertFalse(ChatGenerationState::acceptsState(
            ['messageId' => 'm', 'status' => 'running'], 'chat.snapshot', 'm',
        ));
    }

    public function testCaptureKeepsRawGenerationButWhitelistsPublicStatus(): void
    {
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willReturn(['messageId' => 'm', 'status' => 'deleted']);
        $snapshot = new ChatGenerationState($redis, new Settings(['redis' => ['prefix' => 'test:']]))
            ->capture('alice', 'thread', static fn (): array => []);
        self::assertSame('m', $snapshot['generationMessageId']);
        self::assertSame(['messageId' => 'm', 'status' => 'deleted'], $snapshot['generation']);
        self::assertNull($snapshot['generationStatus']);
        self::assertNull($snapshot['activeMessageId']);
    }

    public function testCaptureOfMissingGenerationHasAnEmptyRawIdentity(): void
    {
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willReturn([]);
        $snapshot = new ChatGenerationState($redis, new Settings(['redis' => ['prefix' => 'test:']]))
            ->capture('alice', 'thread', static fn (): array => []);
        self::assertNull($snapshot['generationMessageId']);
        self::assertSame(['messageId' => '', 'status' => ''], $snapshot['generation']);
        self::assertNull($snapshot['generationStatus']);
    }

    public function testCaptureFailsRatherThanReturningContinuouslyChangingState(): void
    {
        $redis = $this->createMock(RedisClient::class);
        $redis->expects(self::exactly(6))->method('hgetall')->willReturn(
            ['messageId' => 'a'], ['messageId' => 'b'],
            ['messageId' => 'b'], ['messageId' => 'c'],
            ['messageId' => 'c'], ['messageId' => 'd'],
        );
        $this->expectException(\RuntimeException::class);
        new ChatGenerationState($redis, new Settings(['redis' => ['prefix' => 'test:']]))
            ->capture('alice', 'thread', static fn (): array => []);
    }

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
                self::assertSame($accepted, ChatGenerationState::acceptsState(
                    ['messageId' => 'message', 'status' => $status], $event, 'message',
                ));
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
        self::assertSame(['html' => 'complete', 'responding' => false, 'activeMessageId' => null,
            'generationMessageId' => 'message', 'generation' => ['messageId' => 'message', 'status' => 'done'],
            'generationStatus' => 'done'], $snapshot);
    }

    public function testCaptureProjectsOnlySafeTerminalStatusOnReconnect(): void
    {
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willReturn(['status' => 'error', 'messageId' => 'message',
            'last_error' => 'SECRET STACK', 'response' => 'PRIVATE']);
        $state = new ChatGenerationState($redis, new Settings(['redis' => ['prefix' => 'test:']]));
        for ($i = 0; $i < 2; $i++) {
            self::assertSame(['messages' => [], 'responding' => false, 'activeMessageId' => null,
                'generationMessageId' => 'message', 'generation' => ['messageId' => 'message', 'status' => 'error'],
                'generationStatus' => 'error'], $state->capture('alice', 'thread', static function (array $generation): array {
                    self::assertSame('error', $generation['status']);
                    return ['messages' => []];
                }));
        }
    }

    public function testDiagnosticOnlyReturnsExplicitMetadata(): void
    {
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hgetall')->willReturn(['status' => 'queued', 'attempted' => '1',
            'messageId' => 'message', 'jobId' => 'job', 'queue' => 'default', 'jobMessageId' => 'message',
            'response' => 'PRIVATE', 'last_error' => 'SECRET']);
        $state = new ChatGenerationState($redis, new Settings(['redis' => ['prefix' => 'test:']]));
        self::assertSame(['status' => 'queued', 'attempted' => '1', 'messageId' => 'message',
            'jobId' => 'job', 'queue' => 'default'], $state->diagnostic('user', 'thread'));
    }

    public function testHsetTransitionIgnoresStaleAndLegacyJobPointersWithoutMutatingReads(): void
    {
        $storage = ['messageId' => 'web', 'status' => 'queued', 'attempted' => '0',
            'jobId' => 'web-job', 'queue' => 'web-queue', 'jobMessageId' => 'web'];
        $redis = $this->createStub(RedisClient::class);
        $redis->method('hset')->willReturnCallback(static function (string $key, array $values) use (&$storage): int {
            $storage = array_replace($storage, $values);
            return 1;
        });
        $redis->method('hgetall')->willReturnCallback(static function () use (&$storage): array {
            return $storage;
        });
        $state = new ChatGenerationState($redis, new Settings(['redis' => ['prefix' => 'test:']]));
        $state->set('user', 'thread', 'web', 'done', true);
        self::assertSame('web-job', $state->diagnostic('user', 'thread')['jobId']);
        $state->set('user', 'thread', 'telegram', 'running', true);
        self::assertNull($state->diagnostic('user', 'thread')['jobId']);
        self::assertNull($state->diagnostic('user', 'thread')['queue']);
        self::assertSame('web-job', $storage['jobId']);
        unset($storage['jobMessageId']);
        $storage['messageId'] = 'web';
        self::assertNull($state->diagnostic('user', 'thread')['jobId']);
        self::assertSame('web-job', $storage['jobId']);
    }
}
