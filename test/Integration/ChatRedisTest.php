<?php

declare(strict_types=1);

namespace App\Test\Integration;

use App\Services\ChatGenerationState;
use App\Services\ChatStreamPublisher;
use App\Services\ChatStreamSubscriber;
use App\Services\RedisClient;
use App\Services\Settings;
use PHPUnit\Framework\TestCase;

/** Opt-in integration test: never uses the application's Redis configuration. */
final class ChatRedisTest extends TestCase
{
    private RedisClient $writer;
    private RedisClient $reader;
    private \Redis $inspector;
    private Settings $settings;
    private string $prefix;

    protected function setUp(): void
    {
        $port = getenv('CLAIRE_CHAT_TEST_REDIS_PORT');
        if ($port === false || ! extension_loaded('redis')) {
            self::markTestSkipped('Requires ext-redis and explicit CLAIRE_CHAT_TEST_REDIS_PORT');
        }
        $host = getenv('CLAIRE_CHAT_TEST_REDIS_HOST') ?: '127.0.0.1';
        $this->prefix = 'claire-chat-integration:' . bin2hex(random_bytes(16)) . ':';
        $this->settings = new Settings(['redis' => ['prefix' => $this->prefix], 'sse' => ['queue_ttl' => 60]]);
        $this->writer = new RedisClient();
        $this->reader = new RedisClient();
        $this->inspector = new \Redis();
        self::assertTrue($this->writer->connect($host, (int) $port, 3.0));
        self::assertTrue($this->reader->connect($host, (int) $port, 3.0));
        self::assertTrue($this->inspector->connect($host, (int) $port, 3.0));
    }

    protected function tearDown(): void
    {
        if (! isset($this->inspector)) {
            return;
        }
        // Exact keys only: no FLUSHDB, KEYS, SCAN or deletion of another agent's data.
        foreach (['alice', 'bob'] as $user) {
            $this->inspector->del(
                $this->prefix . 'chat:generation:'
                    . hash('sha256', json_encode([$user, 'thread'], JSON_THROW_ON_ERROR)),
                $this->prefix . 'sse:chat:' . ChatStreamSubscriber::scope($user, 'shared-tab') . ':queue',
            );
        }
        $this->writer->close();
        $this->reader->close();
        $this->inspector->close();
    }

    public function testSnapshotStateAndSseRoutingAcrossIndependentRedisConnections(): void
    {
        $subscriber = new ChatStreamSubscriber($this->reader, $this->settings);
        $publisher = new ChatStreamPublisher($this->writer, $subscriber, $this->settings);
        $state = $publisher->generationState();
        $observer = new ChatGenerationState($this->reader, $this->settings);
        $channel = ChatStreamSubscriber::scope('alice', 'shared-tab');
        $state->set('alice', 'thread', 'active-message', 'queued', false);
        self::assertSame(['responding' => false, 'activeMessageId' => null], $observer->snapshot('bob', 'thread'));

        foreach (['queued', 'running', 'done', 'error', 'deleted'] as $status) {
            $state->set('alice', 'thread', 'active-message', $status, $status !== 'queued');
            $snapshot = $observer->snapshot('alice', 'thread');
            $responding = in_array($status, ['queued', 'running'], true);
            self::assertSame($responding,
                $observer->acceptsEvent('alice', 'thread', 'chat.assistant.start', 'active-message'));
            self::assertSame($status === 'done',
                $observer->acceptsEvent('alice', 'thread', 'chat.assistant.done', 'active-message'));
            self::assertSame($status === 'error',
                $observer->acceptsEvent('alice', 'thread', 'chat.error', 'active-message'));
            self::assertSame(['responding' => $responding,
                'activeMessageId' => $responding ? 'active-message' : null], $snapshot);
            $publisher->publish($channel, 'chat.snapshot', [
                'threadId' => 'thread', 'sessionId' => $channel, 'html' => '<p>History</p>', ...$snapshot,
            ]);
            $ttl = $this->inspector->ttl($subscriber->channel($channel) . ':queue');
            self::assertGreaterThan(0, $ttl);
            self::assertLessThanOrEqual(60, $ttl);
            if ($status === 'queued') {
                self::assertNull($subscriber->popMessage(ChatStreamSubscriber::scope('bob', 'shared-tab'), 1));
            }
            $message = $subscriber->popMessage($channel, 1);
            self::assertNotNull($message);
            $event = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('chat.snapshot', $event['event']);
            self::assertSame('thread', $event['threadId']);
            self::assertSame('shared-tab', $event['payload']['sessionId']);
            self::assertSame($responding, $event['payload']['responding']);
            self::assertSame($snapshot['activeMessageId'], $event['payload']['activeMessageId']);
        }
        self::assertTrue($this->reader->reconnect());
        $persisted = new ChatGenerationState($this->reader, $this->settings)->get('alice', 'thread');
        self::assertSame('deleted', $persisted['status']);
        self::assertSame('1', $persisted['attempted']);
        self::assertSame(-1, $this->inspector->ttl($this->prefix . 'chat:generation:'
            . hash('sha256', json_encode(['alice', 'thread'], JSON_THROW_ON_ERROR))));
    }

    public function testCaptureReloadsAfterCompletionWrittenThroughAnotherConnection(): void
    {
        $state = new ChatGenerationState($this->writer, $this->settings);
        $observer = new ChatGenerationState($this->reader, $this->settings);
        $state->set('alice', 'thread', 'message', 'running', true);
        $reads = 0;
        $snapshot = $observer->capture('alice', 'thread', static function () use ($state, &$reads): array {
            if (++$reads === 1) {
                $state->set('alice', 'thread', 'message', 'done', true);
                return ['html' => 'partial'];
            }
            return ['html' => 'complete'];
        });
        self::assertSame(2, $reads);
        self::assertSame(['html' => 'complete', 'responding' => false, 'activeMessageId' => null], $snapshot);
    }

    public function testPublicationSucceedsWhenEventIsConsumedBeforeExpire(): void
    {
        $this->writer->close();
        $this->writer = new class ($this->reader) extends RedisClient {
            public ?array $consumed = null;
            public ?bool $expired = null;

            public function __construct(private readonly RedisClient $consumer)
            {
                parent::__construct();
            }

            public function expire(string $key, int $seconds): bool
            {
                $this->consumed = $this->consumer->brpop([$key], 1);
                return $this->expired = parent::expire($key, $seconds);
            }
        };
        self::assertTrue($this->writer->connect(
            getenv('CLAIRE_CHAT_TEST_REDIS_HOST') ?: '127.0.0.1',
            (int) getenv('CLAIRE_CHAT_TEST_REDIS_PORT'),
            3.0,
        ));
        $subscriber = new ChatStreamSubscriber($this->reader, $this->settings);
        $channel = ChatStreamSubscriber::scope('alice', 'shared-tab');
        new ChatStreamPublisher($this->writer, $subscriber, $this->settings)->publish(
            $channel, 'chat.snapshot', ['threadId' => 'thread', 'html' => '<p>Delivered</p>'],
        );

        self::assertNotNull($this->writer->consumed);
        self::assertFalse($this->writer->expired);
        self::assertSame(0, $this->inspector->exists($subscriber->channel($channel) . ':queue'));
        $event = json_decode($this->writer->consumed[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('chat.snapshot', $event['event']);
        self::assertSame('<p>Delivered</p>', $event['payload']['html']);
    }
}
