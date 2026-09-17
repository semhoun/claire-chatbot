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
    private Settings $settings;
    private string $prefix;
    /** @var list<resource> */
    private array $sockets = [];

    protected function setUp(): void
    {
        $port = getenv('CLAIRE_CHAT_TEST_REDIS_PORT');
        if ($port === false || ! extension_loaded('redis')) {
            self::markTestSkipped('Requires ext-redis and explicit CLAIRE_CHAT_TEST_REDIS_PORT');
        }
        $host = getenv('CLAIRE_CHAT_TEST_REDIS_HOST') ?: '127.0.0.1';
        $this->prefix = 'claire-chat-integration:' . bin2hex(random_bytes(16)) . ':';
        $this->settings = new Settings(['redis' => ['prefix' => $this->prefix]]);
        $this->writer = new RedisClient();
        $this->reader = new RedisClient();
        self::assertTrue($this->writer->connect($host, (int) $port, 3.0));
        self::assertTrue($this->reader->connect($host, (int) $port, 3.0));
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            fclose($socket);
        }
        if (! isset($this->writer)) {
            return;
        }
        // Exact keys only: never flush or enumerate the Redis database.
        $this->writer->del(ChatGenerationState::stateKey($this->prefix, 'alice', 'thread'));
        $this->writer->close();
        $this->reader->close();
    }

    public function testPublicationIsEphemeralIsolatedAndFansOutAfterSubscriptionAck(): void
    {
        $subscriber = new ChatStreamSubscriber($this->settings);
        $publisher = new ChatStreamPublisher($this->writer, $subscriber, $this->settings);
        $scope = ChatStreamSubscriber::scope('alice', 'tab');
        $channel = $subscriber->channel($scope);
        self::assertSame(0, $this->writer->publish($channel, 'lost'));
        $publisher->publish($scope, 'chat.snapshot', ['threadId' => 'thread', 'marker' => 'lost']);

        $first = $this->subscribe($channel);
        $second = $this->subscribe($channel);
        $otherUser = $this->subscribe($subscriber->channel(ChatStreamSubscriber::scope('bob', 'tab')));
        $otherTab = $this->subscribe($subscriber->channel(ChatStreamSubscriber::scope('alice', 'other-tab')));
        $publisher->publish($scope, 'chat.snapshot', ['threadId' => 'thread', 'marker' => 'live']);
        foreach ([$first, $second] as $socket) {
            $frame = $this->readFrame($socket);
            self::assertSame(['message', $channel], array_slice($frame, 0, 2));
            $event = json_decode($frame[2], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(1, $event['version']);
            self::assertSame('chat.snapshot', $event['event']);
            self::assertSame('thread', $event['threadId']);
            self::assertSame('tab', $event['payload']['sessionId']);
            self::assertSame('live', $event['payload']['marker']);
        }
        $read = [$first, $second, $otherUser, $otherTab];
        $write = $except = [];
        self::assertSame(0, stream_select($read, $write, $except, 0, 50000));
        self::assertSame([], $this->reader->hgetall($channel . ':queue'));
    }

    public function testCaptureReloadsAfterCompletionWrittenThroughAnotherConnection(): void
    {
        $state = new ChatGenerationState($this->writer, $this->settings);
        $observer = new ChatGenerationState($this->reader, $this->settings);
        $state->set('alice', 'thread', 'message', 'running', true);
        self::assertSame([], $observer->get('bob', 'thread'));
        $reads = 0;
        $snapshot = $observer->capture('alice', 'thread', static function () use ($state, &$reads): array {
            if (++$reads === 1) {
                $state->set('alice', 'thread', 'message', 'done', true);
                return ['html' => 'partial'];
            }
            return ['html' => 'complete'];
        });
        self::assertSame(2, $reads);
        self::assertSame(['html' => 'complete', 'responding' => false, 'activeMessageId' => null,
            'generationMessageId' => 'message', 'generation' => ['messageId' => 'message', 'status' => 'done'],
            'generationStatus' => 'done', 'submissionId' => null, 'turnStatus' => null,
            'rollbackConfirmed' => false], $snapshot);
        self::assertTrue($this->reader->reconnect());
        self::assertTrue($observer->acceptsEvent('alice', 'thread', 'chat.assistant.done', 'message'));
        self::assertTrue($observer->acceptsEvent('alice', 'thread', 'chat.assistant.update', 'message'));
    }

    public function testQueuePrimitivesStillSupportFractionalBlockingTimeouts(): void
    {
        $key = $this->prefix . 'jobs';
        self::assertSame(1, $this->writer->lpush($key, ['job']));
        self::assertSame([$key, 'job'], $this->reader->brpop([$key], 1));
        $this->reader->setReadTimeout(2);
        $started = microtime(true);
        self::assertNull($this->reader->brpop([$key], 0.05));
        self::assertLessThan(1, microtime(true) - $started);
    }

    /** @return resource */
    private function subscribe(string $channel)
    {
        $host = getenv('CLAIRE_CHAT_TEST_REDIS_HOST') ?: '127.0.0.1';
        $socket = stream_socket_client('tcp://' . $host . ':' . getenv('CLAIRE_CHAT_TEST_REDIS_PORT'),
            timeout: 3);
        self::assertIsResource($socket);
        $this->sockets[] = $socket;
        stream_set_timeout($socket, 2);
        $command = "*2\r\n$9\r\nSUBSCRIBE\r\n$" . strlen($channel) . "\r\n" . $channel . "\r\n";
        self::assertSame(strlen($command), fwrite($socket, $command));
        self::assertSame(['subscribe', $channel, 1], $this->readFrame($socket));
        return $socket;
    }

    /** Minimal RESP2 reader for subscription ACKs and message frames.
     * @param resource $socket
     */
    private function readFrame($socket): array|string|int
    {
        $line = fgets($socket);
        self::assertIsString($line, 'Redis frame timed out');
        $length = (int) substr($line, 1);
        if ($line[0] === ':') {
            return $length;
        }
        if ($line[0] === '*') {
            $items = [];
            for ($i = 0; $i < $length; $i++) {
                $items[] = $this->readFrame($socket);
            }
            return $items;
        }
        self::assertSame('$', $line[0]);
        $value = '';
        while (strlen($value) < $length + 2) {
            $chunk = fread($socket, $length + 2 - strlen($value));
            self::assertNotSame(false, $chunk);
            self::assertNotSame('', $chunk);
            $value .= $chunk;
        }
        return substr($value, 0, $length);
    }
}
