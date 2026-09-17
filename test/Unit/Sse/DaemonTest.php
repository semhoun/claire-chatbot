<?php

declare(strict_types=1);

namespace Tests\Unit\Sse;

use App\Services\Settings;
use App\Sse\Async;
use App\Sse\Backend;
use App\Sse\Budget;
use App\Sse\Config;
use App\Sse\Connection;
use App\Sse\HttpBackend;
use App\Sse\Output;
use App\Sse\PubSubClient;
use App\Sse\RedisGateway;
use App\Sse\RedisHub;
use App\Sse\Server;
use Evenement\EventEmitter;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;
use React\Http\Message\ServerRequest;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ThroughStream;
use React\Stream\CompositeStream;

use function React\Promise\resolve;
use function React\Promise\set_rejection_handler;

final class DaemonTest extends TestCase
{
    private ManualLoop $loop;
    private FakeBackend $backend;
    private FakeRedis $redis;
    private Budget $budget;
    private array $closed;
    private array $frames;

    protected function setUp(): void
    {
        $this->loop = new ManualLoop();
        $this->backend = new FakeBackend();
        $this->redis = new FakeRedis();
        $this->budget = new Budget(134217728);
        $this->closed = $this->frames = [];
    }

    private function settings(array $overrides = []): Settings
    {
        return new Settings(['base_url' => 'https://claire.test/base',
            'security' => ['cors' => ['allowed_origins' => ['https://widget.test']]],
            'redis' => ['prefix' => 'test:', 'host' => '127.0.0.1', 'port' => 6379,
                'database' => 3, 'password' => 'p:a@ss', 'timeout' => 2],
            'sse' => [...[
                'secret' => 'test-secret', 'duration' => 1800, 'max_duration' => 86400,
                'listen' => '127.0.0.1:8081', 'backend' => 'http://127.0.0.1:8082',
                'check_interval' => 15, 'keepalive' => 15, 'http_timeout' => 10,
                'max_connections' => 1000, 'max_http_requests' => 16, 'max_pending_events' => 256,
                'max_client_buffer' => 16777216, 'max_global_buffer' => 134217728,
                'write_timeout' => 15, 'shutdown_timeout' => 5, 'max_redis_commands' => 64,
            ], ...$overrides]]);
    }

    private function authorization(array $overrides = []): array
    {
        return [...['authorization' => str_repeat('a', 64), 'userId' => 'user', 'threadId' => 'thread',
            'sessionId' => 'tab', 'openedAt' => 1000.0, 'deadline' => 2800.0, 'remember' => null], ...$overrides];
    }

    private function snapshot(string $message = 'm1', string $status = 'running'): array
    {
        return ['threadId' => 'thread', 'sessionId' => 'tab',
            'messages' => [], 'responding' => in_array($status, ['running', 'queued'], true),
            'activeMessageId' => $status === 'running' ? $message : null,
            'generationMessageId' => $message, 'generationStatus' => $status, 'audioRequestIds' => [],
            'generation' => ['messageId' => $message, 'status' => $status]];
    }

    private function connection(array $settings = [], array $authorization = []): Connection
    {
        $connection = new Connection('1', 'channel', $this->authorization($authorization),
            new Config($this->settings($settings)), $this->loop, $this->backend, $this->redis, $this->budget,
            fn (): float => $this->loop->now,
            function (string $id, string $reason): void { $this->closed[] = [$id, $reason]; });
        $connection->output()->on('data', function (string $frame): void { $this->frames[] = $frame; });
        $connection->start()->then(null, static function (): void {});
        return $connection;
    }

    private function live(Connection $connection): void
    {
        $this->redis->ack->resolve(null);
        $this->backend->requests[0][2]->resolve($this->snapshot());
        $connection->output()->resume();
        $this->loop->tick();
    }

    private function event(string $name = 'chat.assistant.update', array $payload = []): string
    {
        return json_encode(['version' => 1, 'event' => $name, 'threadId' => 'thread', 'payload' => [
            ...['threadId' => 'thread', 'sessionId' => 'tab', 'messageId' => 'm1'], ...$payload,
        ]], JSON_THROW_ON_ERROR);
    }

    public function testAckPrecedesSnapshotAndEventsDuringHandshakeFollowSnapshot(): void
    {
        $connection = $this->connection();
        self::assertCount(0, $this->backend->requests);
        $connection->receive($this->event());
        $this->loop->tick();
        self::assertSame([], $this->frames);
        $this->live($connection);
        self::assertCount(2, $this->frames);
        self::assertStringStartsWith('event: chat.snapshot', $this->frames[0]);
        self::assertStringStartsWith('event: chat.assistant.update', $this->frames[1]);
        self::assertSame(0, $this->budget->bytes());
        $connection->close('test');
        self::assertSame([], $this->loop->timers);
    }

    public function testSnapshotForwardsSqlCorrelationWithRawRedisSignature(): void
    {
        $connection = $this->connection();
        $this->redis->ack->resolve(null);
        $this->backend->requests[0][2]->resolve([...$this->snapshot(),
            'responding' => false, 'activeMessageId' => null, 'generationStatus' => 'error',
            'submissionId' => 'submission-1', 'turnStatus' => 'rolled_back', 'rollbackConfirmed' => true]);
        $connection->output()->resume();
        $this->loop->tick();
        self::assertSame([], $this->closed);
        self::assertStringContainsString('"submissionId":"submission-1"', $this->frames[0]);
        self::assertStringContainsString('"turnStatus":"rolled_back"', $this->frames[0]);
        self::assertStringContainsString('"rollbackConfirmed":true', $this->frames[0]);
        $connection->close('test');
    }

    public function testTerminalEventKeepsCorrelationWithoutInventingRollbackConfirmation(): void
    {
        $connection = $this->connection();
        $this->live($connection);
        $this->redis->state['status'] = 'error';
        $connection->receive($this->event('chat.error', ['submissionId' => 'submission-1',
            'turnStatus' => 'running', 'rollbackConfirmed' => false]));
        $this->loop->tick();
        self::assertStringContainsString('"submissionId":"submission-1"', $this->frames[1]);
        self::assertStringContainsString('"rollbackConfirmed":false', $this->frames[1]);
        $connection->close('test');
    }

    public function testTerminalBeforeSnapshotReturnRecapturesAndPreservesOrderedUpdate(): void
    {
        $connection = $this->connection();
        $this->redis->ack->resolve(null);
        $connection->receive($this->event());
        $connection->receive($this->event('chat.assistant.done'));
        $this->redis->state['status'] = 'done';
        $this->backend->requests[0][2]->resolve($this->snapshot());
        self::assertCount(2, $this->backend->requests);
        self::assertSame([], $this->frames);
        $this->backend->requests[1][2]->resolve($this->snapshot('m1', 'done'));
        $connection->output()->resume();
        $this->loop->tick();
        self::assertCount(3, $this->backend->requests);
        $this->backend->requests[2][2]->resolve($this->snapshot('m1', 'done'));
        self::assertCount(4, $this->frames);
        self::assertStringContainsString('chat.assistant.update', $this->frames[1]);
        self::assertStringContainsString('chat.assistant.done', $this->frames[2]);
        self::assertStringStartsWith('event: chat.snapshot', $this->frames[3]);
        $connection->close('test');
    }

    public function testGenerationReplacementHasBoundedRecapture(): void
    {
        $connection = $this->connection();
        $this->redis->ack->resolve(null);
        $this->redis->state['messageId'] = 'replacement';
        for ($index = 0; $index < 3; ++$index) {
            $this->backend->requests[$index][2]->resolve($this->snapshot());
        }
        self::assertSame([['1', 'snapshot_unstable']], $this->closed);
        self::assertSame([], $this->frames);
        self::assertSame(0, $this->budget->bytes());
    }

    public function testCallbacksAfterCloseCannotWriteOrRestartWork(): void
    {
        $connection = $this->connection();
        $this->redis->ack->resolve(null);
        $connection->close('test');
        $this->backend->requests[0][2]->resolve($this->snapshot());
        $this->redis->emit($this->event());
        $this->loop->tick();
        self::assertSame([], $this->frames);
        self::assertSame([], $this->loop->timers);
        self::assertSame([str_repeat('a', 64)], $this->backend->closed);
        self::assertSame(0, $this->budget->bytes());
    }

    public function testDuplicateSnapshotRequestsCoalesce(): void
    {
        $connection = $this->connection();
        $this->live($connection);
        $connection->requestSnapshot();
        $connection->requestSnapshot();
        $this->loop->tick();
        $connection->requestSnapshot();
        self::assertCount(2, $this->backend->requests);
        $this->backend->requests[1][2]->resolve($this->snapshot());
        $this->loop->tick();
        self::assertCount(2, $this->backend->requests);
        $connection->close('test');
    }

    public function testEventsRemainSerializedAcrossDelayedGenerationReads(): void
    {
        $connection = $this->connection();
        $this->live($connection);
        $read = $this->redis->deferredGeneration = new Deferred();
        $connection->receive($this->event(payload: ['text' => 'first']));
        $connection->receive($this->event(payload: ['text' => 'second']));
        $this->loop->tick();
        self::assertCount(1, $this->frames);
        $this->redis->deferredGeneration = null;
        $read->resolve($this->redis->state);
        $this->loop->tick();
        self::assertStringContainsString('first', $this->frames[1]);
        self::assertStringContainsString('second', $this->frames[2]);
        $connection->close('test');
        self::assertSame(0, $this->budget->bytes());
    }

    public function testWrongScopeUnknownEventsAndOldGenerationAreDropped(): void
    {
        $connection = $this->connection();
        $this->live($connection);
        foreach ([$this->event(payload: ['sessionId' => 'other']), $this->event(payload: ['threadId' => 'other']),
            $this->event(payload: ['messageId' => 'old']), $this->event('injected'), '{bad', 'null'] as $raw) {
            $connection->receive($raw);
        }
        $this->loop->tick();
        self::assertCount(1, $this->frames);
        $connection->close('test');
    }

    public function testLargeAudioAllowedWithoutGenerationMatchAndOversizedAudioCloses(): void
    {
        $connection = $this->connection();
        $this->live($connection);
        $connection->receive($this->event('chat.audio.ready', ['messageId' => 'historic',
            'audioRequestId' => 'audio', 'audio' => str_repeat('a', 2 * 1024 * 1024)]));
        $this->loop->tick();
        self::assertCount(2, $this->frames);
        self::assertGreaterThan(2 * 1024 * 1024, strlen($this->frames[1]));
        $connection->receive(str_repeat('a', 16777217));
        self::assertSame([['1', 'event_buffer_limit']], $this->closed);
        self::assertSame(0, $this->budget->bytes());
    }

    public function testPendingEventCountIsBoundedBeforeAck(): void
    {
        $connection = $this->connection(['max_pending_events' => 1]);
        $connection->receive($this->event());
        $connection->receive($this->event());
        self::assertSame([['1', 'event_buffer_limit']], $this->closed);
        self::assertSame(0, $this->budget->bytes());
    }

    public function testSlowClientRetainsAccountingAndStopsAtWriteTimeout(): void
    {
        $connection = $this->connection();
        $this->redis->ack->resolve(null);
        $this->backend->requests[0][2]->resolve($this->snapshot());
        self::assertGreaterThan(0, $this->budget->bytes());
        $this->loop->advance(15);
        self::assertSame([['1', 'write_timeout']], $this->closed);
        self::assertSame(0, $this->budget->bytes());
    }

    public function testDownstreamPauseAndDrainDoNotLoseOrReorderEvents(): void
    {
        $connection = $this->connection();
        $this->live($connection);
        $connection->output()->on('data', $pause = fn () => $connection->output()->pause());
        $connection->receive($this->event(payload: ['text' => 'first']));
        $connection->receive($this->event(payload: ['text' => 'second']));
        $this->loop->tick();
        self::assertCount(2, $this->frames);
        self::assertGreaterThan(0, $this->budget->bytes());
        $connection->output()->removeListener('data', $pause);
        $connection->output()->resume();
        $this->loop->tick();
        self::assertCount(3, $this->frames);
        self::assertSame(0, $this->budget->bytes());
        $connection->close('test');
    }

    public function testRememberRevocationClosesOnIndependentTimer(): void
    {
        $connection = $this->connection(authorization: ['remember' => ['id' => str_repeat('b', 64), 'expires' => 2000]]);
        $this->live($connection);
        $this->redis->rememberRecord = ['user_id' => 'other', 'expires' => 2000];
        $this->loop->advance(15);
        self::assertSame([['1', 'remember_revoked']], $this->closed);
    }

    public function testNaturalRememberExpirationAndAbsoluteDeadline(): void
    {
        $connection = $this->connection(authorization: ['remember' => ['id' => str_repeat('b', 64), 'expires' => 1002]]);
        $this->live($connection);
        $this->loop->advance(2);
        self::assertSame([['1', 'remember_expired']], $this->closed);
        $this->closed = [];
        $this->backend->requests = [];
        $connection = $this->connection(authorization: ['deadline' => 1004.0]);
        $this->backend->requests[0][2]->resolve($this->snapshot());
        $connection->output()->resume();
        $this->loop->advance(2);
        self::assertSame([['1', 'deadline']], $this->closed);
    }

    public function testGenerationCheckDetectsChangesFromOtherTabs(): void
    {
        $connection = $this->connection();
        $this->live($connection);
        $this->loop->advance(15);
        self::assertCount(1, $this->backend->requests);
        $this->redis->state['messageId'] = 'm2';
        $this->loop->advance(15);
        self::assertCount(2, $this->backend->requests);
        $connection->close('test');
    }

    public function testBackendFailureAndDeadlineDuringHandshakeNeverEmitSnapshot(): void
    {
        $connection = $this->connection();
        $this->redis->ack->resolve(null);
        $this->backend->requests[0][2]->reject(new \RuntimeException('timeout'));
        self::assertSame([['1', 'snapshot_failed']], $this->closed);
        self::assertSame([], $this->frames);
    }

    public function testRestoredMessageOnlySurvivesMatchingGeneration(): void
    {
        $connection = $this->connection();
        $this->live($connection);
        $connection->receive($this->event('chat.snapshot', ['restoredMessage' => 'restore me',
            'generationMessageId' => 'm1', 'generationStatus' => 'running']));
        $this->loop->tick();
        $this->backend->requests[1][2]->resolve($this->snapshot());
        self::assertStringContainsString('restore me', $this->frames[1]);
        $connection->receive($this->event('chat.snapshot', ['restoredMessage' => 'stale',
            'generationMessageId' => 'old', 'generationStatus' => 'running']));
        $this->loop->tick();
        $this->backend->requests[2][2]->resolve($this->snapshot());
        self::assertStringNotContainsString('stale', $this->frames[2]);
        $connection->close('test');
    }

    public function testOutputSharesGlobalBudgetAndReleasesOnClose(): void
    {
        $budget = new Budget(10);
        $first = new Output($budget, 10);
        $second = new Output($budget, 10);
        self::assertTrue($first->send('123456'));
        self::assertFalse($second->send('12345'));
        $first->close();
        self::assertTrue($second->send('12345'));
        $second->close();
        self::assertSame(0, $budget->bytes());
    }

    public function testTimeoutCancelsUnderlyingWorkWithoutWallClockSleep(): void
    {
        $cancelled = false;
        $deferred = new Deferred(static function () use (&$cancelled): void { $cancelled = true; });
        $error = null;
        Async::timeout($deferred->promise(), $this->loop, 2)->then(null,
            static function (\Throwable $failure) use (&$error): void { $error = $failure; });
        $this->loop->advance(2);
        self::assertTrue($cancelled);
        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertSame([], $this->loop->timers);
    }

    public function testPublicValidationCorsAndAdmissionLimits(): void
    {
        $settings = $this->settings(['max_connections' => 1]);
        $server = new Server($settings, new Config($settings), $this->loop, $this->backend, $this->redis,
            $this->budget, fn (): float => $this->loop->now);
        foreach (['/base/brain/stream?threadId=x&sessionId=y&token=a&token=b',
            '/base/brain/stream?threadId[]=x&sessionId=y&token=a',
            '/base/brain/stream?threadId=x&sessionId=y&token=a&authorization=b'] as $uri) {
            $response = $server->handle(new ServerRequest('GET', 'https://claire.test' . $uri,
                ['Origin' => 'https://widget.test']));
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('https://widget.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
            self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        }
        $request = new ServerRequest('GET', 'https://claire.test/base/brain/stream?threadId=thread&sessionId=tab&token=abc');
        $pending = $server->handle($request);
        self::assertInstanceOf(PromiseInterface::class, $pending);
        self::assertSame('capability', $this->backend->requests[0][1]['credentialType']);
        self::assertSame('/base/brain/stream', $this->backend->requests[0][1]['path']);
        self::assertSame(503, $server->handle($request)->getStatusCode());
        $server->stop();
        self::assertSame(0, $server->metrics()['admissions']);
    }

    public function testServerAcceptsFractionalDeadlineAndShutsDownAllReferences(): void
    {
        $settings = $this->settings();
        $server = new Server($settings, new Config($settings), $this->loop, $this->backend, $this->redis,
            $this->budget, fn (): float => $this->loop->now);
        $request = new ServerRequest('GET', 'https://claire.test/base/brain/stream?threadId=thread&sessionId=tab',
            ['X-Claire-Auth' => 'credential']);
        $response = null;
        $server->handle($request)->then(static function ($value) use (&$response): void { $response = $value; });
        $this->backend->requests[0][2]->resolve($this->authorization(['openedAt' => 999.5, 'deadline' => 2799.5]));
        $this->redis->ack->resolve(null);
        $this->backend->requests[1][2]->resolve($this->snapshot());
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Claire-Token'));
        $server->stop();
        self::assertSame(0, $server->metrics()['connections']);
        self::assertSame([], $this->redis->listeners);
        self::assertSame([], $this->loop->timers);
        self::assertSame(0, $this->budget->bytes());
    }

    public function testRedisHubSharesAckFanoutReadsAndReconnectsWithoutOldListeners(): void
    {
        $subscriber = new FakeRedisClient();
        $commands = new FakeRedisClient();
        $clients = [$subscriber, $commands, new FakeRedisClient(), new FakeRedisClient()];
        $uris = [];
        $lost = 0;
        $settings = $this->settings(['max_redis_commands' => 1]);
        $hub = new RedisHub($settings, new Config($settings), $this->loop,
            static function () use (&$lost): void { ++$lost; },
            static function (string $uri) use (&$clients, &$uris): PromiseInterface {
                $uris[] = $uri;
                return resolve(array_shift($clients));
            }, static fn (): float => 1.0);
        $hub->start();
        self::assertTrue($hub->ready());
        self::assertCount(2, $uris);
        self::assertStringContainsString(':p%3Aa%40ss@127.0.0.1:6379/3', $uris[0]);
        $delivered = [];
        $first = $hub->subscribe('channel', 'one', static function ($raw) use (&$delivered): void { $delivered[] = '1' . $raw; });
        $second = $hub->subscribe('channel', 'two', static function ($raw) use (&$delivered): void { $delivered[] = '2' . $raw; });
        self::assertSame($first, $second);
        self::assertCount(1, $subscriber->calls);
        $ack = false;
        $first->then(static function () use (&$ack): void { $ack = true; });
        self::assertFalse($ack);
        $subscriber->calls[0][2]->resolve(1);
        self::assertTrue($ack);
        $subscriber->emit('message', ['channel', 'payload']);
        self::assertSame(['1payload', '2payload'], $delivered);
        $hub->generation('user', 'thread')->then(static function (): void {});
        $hub->generation('user', 'thread')->then(static function (): void {});
        self::assertCount(1, $commands->calls);
        $rejected = false;
        $hub->remember('id')->then(null, static function () use (&$rejected): void { $rejected = true; });
        self::assertFalse($rejected);
        self::assertSame(1, $hub->metrics()['redis_commands_waiting']);
        $commands->calls[0][2]->resolve(['messageId', 'm1', 'status', 'running']);
        self::assertCount(2, $commands->calls);
        $commands->calls[1][2]->resolve(null);
        $hub->unsubscribe('channel', 'one');
        self::assertCount(1, $subscriber->calls);
        $hub->unsubscribe('channel', 'two');
        self::assertSame('unsubscribe', $subscriber->calls[1][0]);
        $subscriber->calls[1][2]->resolve(0);
        self::assertSame(0, $hub->subscriptionCount());
        $subscriber->close();
        self::assertFalse($hub->ready());
        self::assertSame(1, $lost);
        $this->loop->advance(0.25);
        self::assertTrue($hub->ready());
        self::assertCount(4, $uris);
        self::assertSame(0, $hub->subscriptionCount());
        $hub->stop();
        self::assertSame([], $this->loop->timers);
    }

    public function testRedisUnsubscribeResubscribeRaceWaitsForBothAcks(): void
    {
        $subscriber = new FakeRedisClient();
        $clients = [$subscriber, new FakeRedisClient()];
        $settings = $this->settings();
        $hub = new RedisHub($settings, new Config($settings), $this->loop, static function (): void {},
            static function () use (&$clients): PromiseInterface { return resolve(array_shift($clients)); });
        $hub->start();
        $hub->subscribe('channel', 'one', static function (): void {});
        $hub->unsubscribe('channel', 'one');
        $ready = false;
        $hub->subscribe('channel', 'two', static function (): void {})->then(
            static function () use (&$ready): void { $ready = true; });
        self::assertCount(1, $subscriber->calls);
        $subscriber->calls[0][2]->resolve(1);
        self::assertSame('unsubscribe', $subscriber->calls[1][0]);
        self::assertFalse($ready);
        $subscriber->calls[1][2]->resolve(0);
        self::assertSame('subscribe', $subscriber->calls[2][0]);
        self::assertFalse($ready);
        $subscriber->calls[2][2]->resolve(1);
        self::assertTrue($ready);
        self::assertSame(1, $hub->subscriptionCount());
        $hub->stop();
        self::assertSame([], $this->loop->timers);
    }

    public function testRepeatedCloseBeforeUnsubscribeAckKeepsThirdAdmissionPhysicallySubscribed(): void
    {
        $subscriber = new FakeRedisClient();
        $clients = [$subscriber, new FakeRedisClient()];
        $settings = $this->settings();
        $hub = new RedisHub($settings, new Config($settings), $this->loop, static function (): void {},
            static function () use (&$clients): PromiseInterface { return resolve(array_shift($clients)); });
        $hub->start();
        $hub->subscribe('channel', 'A', static function (): void { self::fail('Closed A received a publication'); });
        $subscriber->acknowledge(0);
        $hub->unsubscribe('channel', 'A'); // U1 is on the wire, awaiting its ACK.
        $hub->subscribe('channel', 'B', static function (): void { self::fail('Closed B received a publication'); });
        $hub->unsubscribe('channel', 'B'); // U2 is queued behind S2, before U1 ACK.
        $subscriber->acknowledge(1); // S2 is now on the wire, awaiting its ACK.

        $delivered = [];
        $ready = false;
        $hub->subscribe('channel', 'C', static function (string $raw) use (&$delivered): void { $delivered[] = $raw; })
            ->then(static function () use (&$ready): void { $ready = true; });
        self::assertFalse($ready);
        // Execute commands in wire order, including commands released by each ACK.
        for ($index = 2; $index < count($subscriber->calls); ++$index) {
            self::assertLessThan(10, $index);
            $subscriber->acknowledge($index);
        }
        self::assertTrue($ready);
        $subscriber->publish('channel', 'publication for C');
        self::assertSame(['publication for C'], $delivered);
        self::assertSame(['channel' => true], $subscriber->subscribed);
        self::assertSame(['subscribe', 'unsubscribe', 'subscribe', 'unsubscribe', 'subscribe'],
            array_column($subscriber->calls, 0));
        self::assertSame(1, $hub->subscriptionCount());

        $hub->unsubscribe('channel', 'C');
        self::assertSame(1, $hub->subscriptionCount());
        $subscriber->acknowledge(5);
        self::assertSame([], $subscriber->subscribed);
        self::assertSame(0, $hub->subscriptionCount());
        $hub->stop();
        self::assertSame([], $this->loop->timers);
    }

    public function testCancelledAdmissionCleansLateAuthorizationWithoutOpeningConnection(): void
    {
        $settings = $this->settings();
        $server = new Server($settings, new Config($settings), $this->loop, $this->backend, $this->redis,
            $this->budget, fn (): float => $this->loop->now);
        $promise = $server->handle(new ServerRequest('GET',
            'http://claire.test/base/brain/stream?threadId=thread&sessionId=tab&token=secret'));
        $promise->then(null, static function (): void {});
        $promise->cancel();
        $this->backend->requests[0][2]->resolve($this->authorization());
        self::assertSame([str_repeat('a', 64)], $this->backend->closed);
        self::assertSame([], $this->redis->listeners);
        self::assertSame(0, $server->metrics()['connections']);
    }

    public function testCloseDuringGenerationReadReleasesRetainedEventAndSnapshot(): void
    {
        $connection = $this->connection();
        $this->live($connection);
        $read = $this->redis->deferredGeneration = new Deferred();
        $connection->receive($this->event(payload: ['text' => str_repeat('x', 100000)]));
        $this->loop->tick();
        self::assertGreaterThan(100000, $this->budget->bytes());
        $connection->close('test');
        self::assertSame(0, $this->budget->bytes());
        $read->resolve($this->redis->state);
        self::assertCount(1, $this->frames);
        self::assertSame(0, $this->budget->bytes());
    }

    public function testHttpBackendBoundsStreamingBodiesAndWholeResponseTimeout(): void
    {
        $settings = $this->settings(['max_client_buffer' => 1024, 'max_http_requests' => 1]);
        $stream = new ThroughStream();
        $calls = [];
        $backend = new HttpBackend(new Config($settings), $this->loop, $this->budget,
            static function (...$args) use (&$calls, $stream): PromiseInterface {
                $calls[] = $args;
                return resolve(new Response(200, ['Content-Type' => 'application/json'], $stream));
            });
        $errors = [];
        $backend->request('snapshot', ['authorization' => 'opaque'])->then(null,
            static function ($error) use (&$errors): void { $errors[] = $error; });
        $backend->request('open', [])->then(null,
            static function ($error) use (&$errors): void { $errors[] = $error; });
        self::assertCount(1, $calls);
        self::assertSame('http://127.0.0.1:8082/snapshot', $calls[0][1]);
        self::assertSame('test-secret', $calls[0][2]['X-Claire-Sse-Secret']);
        self::assertStringNotContainsString('opaque', $calls[0][1]);
        $stream->write(str_repeat('x', 1000));
        self::assertSame(1000, $this->budget->bytes());
        $stream->write(str_repeat('x', 25));
        self::assertCount(2, $errors);
        self::assertSame(0, $this->budget->bytes());
        self::assertFalse($stream->isReadable());
        self::assertSame(0, $backend->metrics()['http_active']);
        self::assertSame([], $this->loop->timers);

        $stream = new ThroughStream();
        $backend = new HttpBackend(new Config($settings), $this->loop, $this->budget,
            static fn (): PromiseInterface => resolve(new Response(200, [], $stream)));
        $backend->request('snapshot', [])->then(null, static function (): void {});
        $stream->write('{');
        $this->loop->advance(10);
        self::assertSame(0, $this->budget->bytes());
        self::assertFalse($stream->isReadable());
        self::assertSame(0, $backend->metrics()['http_active']);
    }

    public function testHttpBackendRejectsRedirectsUnauthorizedAndOversizedRequests(): void
    {
        foreach ([301, 302, 307, 401, 403] as $status) {
            $stream = new ThroughStream();
            $backend = new HttpBackend(new Config($this->settings()), $this->loop, $this->budget,
                static fn (): PromiseInterface => resolve(new Response($status, ['Location' => 'http://attacker.test'], $stream)));
            $error = null;
            $backend->request('open', [])->then(null, static function ($failure) use (&$error): void { $error = $failure; });
            self::assertSame($status, $error->getCode());
            self::assertFalse($stream->isReadable());
            self::assertSame(0, $this->budget->bytes());
        }
        $error = null;
        $backend->request('open', ['credential' => str_repeat('a', 16384)])->then(null,
            static function ($failure) use (&$error): void { $error = $failure; });
        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertSame([], $this->loop->timers);
    }

    public function testHttpBackendSupports204CleanupAndCancellation(): void
    {
        $backend = new HttpBackend(new Config($this->settings()), $this->loop, $this->budget,
            static fn (): PromiseInterface => resolve(new Response(204)));
        $backend->close('opaque');
        self::assertSame(1, $backend->metrics()['http_completed']);
        self::assertSame(0, $backend->metrics()['http_errors']);
        $stream = new ThroughStream();
        $backend = new HttpBackend(new Config($this->settings()), $this->loop, $this->budget,
            static fn (): PromiseInterface => resolve(new Response(200, [], $stream)));
        $pending = $backend->request('snapshot', []);
        $pending->then(null, static function (): void {});
        $stream->write('buffered');
        $pending->cancel();
        self::assertFalse($stream->isReadable());
        self::assertSame(0, $this->budget->bytes());
        self::assertSame([], $this->loop->timers);
    }

    public function testReactResponseWrapperStartsAfterHeadersAndClosesTransport(): void
    {
        $settings = $this->settings();
        $server = new Server($settings, new Config($settings), $this->loop, $this->backend, $this->redis,
            $this->budget, fn (): float => $this->loop->now);
        $disconnects = 0;
        $wire = new ThroughStream();
        $data = '';
        $wire->on('data', static function (string $chunk) use (&$data): void { $data .= $chunk; });
        $server->handle(new ServerRequest('GET',
            'http://claire.test/base/brain/stream?threadId=thread&sessionId=tab&token=secret'),
            static function () use (&$disconnects): void { ++$disconnects; })
            ->then(static function ($response) use ($wire): void {
                (new \React\Http\Io\ChunkedEncoder($response->getBody()))->pipe($wire);
            });
        $this->backend->requests[0][2]->resolve($this->authorization());
        $this->redis->ack->resolve(null);
        $this->backend->requests[1][2]->resolve($this->snapshot());
        self::assertSame('', $data);
        $this->loop->tick();
        self::assertStringContainsString("event: chat.snapshot\ndata:", $data);
        self::assertSame(0, $this->budget->bytes());
        $server->unavailable();
        self::assertSame(1, $disconnects);
        self::assertSame(0, $server->metrics()['connections']);
        self::assertSame([], $this->loop->timers);
    }

    public function testSecurityReadFailureClosesWhileEventReadIsPending(): void
    {
        $connection = $this->connection();
        $this->live($connection);
        $read = $this->redis->deferredGeneration = new Deferred();
        $connection->receive($this->event());
        $this->loop->tick();
        $read->reject(new \RuntimeException('Redis unavailable'));
        self::assertSame([['1', 'generation_unavailable']], $this->closed);
        self::assertSame(0, $this->budget->bytes());
        self::assertSame([], $this->loop->timers);
    }

    public function testOversizedAndWrongContextSnapshotsAreNeverWritten(): void
    {
        $connection = $this->connection(['max_client_buffer' => 1024]);
        $this->redis->ack->resolve(null);
        $this->backend->requests[0][2]->resolve([...$this->snapshot(), 'messages' => [str_repeat('x', 1024)]]);
        self::assertSame([['1', 'snapshot_buffer_limit']], $this->closed);
        self::assertSame([], $this->frames);
        self::assertSame(0, $this->budget->bytes());
        $this->closed = [];
        $this->backend->requests = [];
        $connection = $this->connection();
        $this->backend->requests[0][2]->resolve([...$this->snapshot(), 'threadId' => 'wrong']);
        self::assertSame([['1', 'invalid_snapshot']], $this->closed);
        self::assertSame([], $this->frames);
    }

    public function testDeadlineReachedBeforeAckPreventsBackendSnapshot(): void
    {
        $connection = $this->connection(authorization: ['deadline' => 1001]);
        $this->loop->advance(1);
        $this->redis->ack->resolve(null);
        self::assertSame([['1', 'deadline']], $this->closed);
        self::assertSame([], $this->backend->requests);
        self::assertSame([], $this->redis->listeners);
    }

    public function testEverySnapshotRequiresBothExactContextIds(): void
    {
        foreach ([false, true] as $initialized) {
            foreach (['threadId', 'sessionId'] as $key) {
                foreach (['missing', null, 123, ['invalid'], 'wrong'] as $invalid) {
                    $this->backend = new FakeBackend();
                    $this->redis = new FakeRedis();
                    $this->frames = $this->closed = [];
                    $connection = $this->connection();
                    if ($initialized) {
                        $this->live($connection);
                        $connection->requestSnapshot();
                        $this->loop->tick();
                    } else {
                        $this->redis->ack->resolve(null);
                    }
                    $snapshot = $this->snapshot();
                    if ($invalid === 'missing') {
                        unset($snapshot[$key]);
                    } else {
                        $snapshot[$key] = $invalid;
                    }
                    $this->backend->requests[$initialized ? 1 : 0][2]->resolve($snapshot);
                    $this->loop->tick();
                    self::assertSame([['1', 'invalid_snapshot']], $this->closed);
                    self::assertCount($initialized ? 1 : 0, $this->frames);
                    self::assertSame(0, $this->budget->bytes());
                    self::assertSame([], $this->redis->listeners);
                    self::assertSame([], $this->loop->timers);
                    self::assertSame([str_repeat('a', 64)], $this->backend->closed);
                }
            }
        }
    }

    public function testServerRejectsMalformedOpaqueAuthorizationBeforeSubscribing(): void
    {
        $settings = $this->settings();
        $server = new Server($settings, new Config($settings), $this->loop, $this->backend, $this->redis,
            $this->budget, fn (): float => $this->loop->now);
        $response = null;
        $server->handle(new ServerRequest('GET',
            'http://claire.test/base/brain/stream?threadId=thread&sessionId=tab&token=secret'))
            ->then(static function ($value) use (&$response): void { $response = $value; });
        $this->backend->requests[0][2]->resolve($this->authorization(['authorization' => str_repeat('z', 64)]));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame([], $this->redis->listeners);
        self::assertSame(0, $server->metrics()['connections']);
        self::assertSame([str_repeat('z', 64)], $this->backend->closed);
    }

    public function testBackendAuthorizationFailurePreservesCorsAndNeverSubscribes(): void
    {
        $settings = $this->settings();
        $server = new Server($settings, new Config($settings), $this->loop, $this->backend, $this->redis,
            $this->budget, fn (): float => $this->loop->now);
        $response = null;
        $server->handle(new ServerRequest('GET',
            'http://claire.test/base/brain/stream?threadId=thread&sessionId=tab&token=secret',
            ['Origin' => 'https://widget.test']))->then(
                static function ($value) use (&$response): void { $response = $value; });
        $this->backend->requests[0][2]->reject(new \RuntimeException('unauthorized', 401));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('https://widget.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame([], $this->redis->listeners);
        self::assertCount(1, $this->backend->requests);
    }

    public function testBackendShutdownCancelsBodiesAndBoundedCleanupQueue(): void
    {
        $settings = $this->settings(['max_http_requests' => 1, 'max_connections' => 2]);
        $stream = new ThroughStream();
        $backend = new HttpBackend(new Config($settings), $this->loop, $this->budget,
            static fn (): PromiseInterface => resolve(new Response(200, [], $stream)));
        $backend->request('snapshot', [])->then(null, static function (): void {});
        $stream->write('pending');
        foreach (['one', 'two', 'three'] as $authorization) { $backend->close($authorization); }
        self::assertSame(2, $backend->metrics()['authorization_cleanup_pending']);
        $backend->stop();
        self::assertSame(0, $backend->metrics()['http_active']);
        self::assertSame(0, $backend->metrics()['authorization_cleanup_pending']);
        self::assertSame(0, $this->budget->bytes());
        self::assertSame([], $this->loop->timers);
    }

    public function testUnsafeOrIncoherentConfigurationFailsBeforeListening(): void
    {
        foreach ([['secret' => ''], ['backend' => 'http://attacker.test'], ['listen' => '0.0.0.0:8081'],
            ['max_duration' => 86401], ['duration' => 90000], ['max_http_requests' => 0],
            ['max_global_buffer' => 1024]] as $settings) {
            try {
                new Config($this->settings($settings));
                self::fail('Unsafe configuration accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAdjacentSnapshotPublicationsAreCoalesced(): void
    {
        $connection = $this->connection();
        $this->live($connection);
        $connection->receive($this->event('chat.snapshot'));
        $connection->receive($this->event('chat.snapshot'));
        $connection->receive($this->event('chat.snapshot'));
        $this->loop->tick();
        self::assertCount(2, $this->backend->requests);
        $this->backend->requests[1][2]->resolve($this->snapshot());
        $this->loop->tick();
        self::assertCount(2, $this->frames);
        self::assertCount(2, $this->backend->requests);
        self::assertSame(0, $this->budget->bytes());
        $connection->close('test');
    }

    public function testQueuedRedisChecksHaveABoundedEndToEndTimeout(): void
    {
        $subscriber = new FakeRedisClient();
        $commands = new FakeRedisClient();
        $clients = [$subscriber, $commands];
        $settings = $this->settings(['max_redis_commands' => 1, 'max_connections' => 1]);
        $lost = 0;
        $hub = new RedisHub($settings, new Config($settings), $this->loop,
            static function () use (&$lost): void { ++$lost; },
            static function () use (&$clients): PromiseInterface { return resolve(array_shift($clients)); },
            static fn (): float => 1.0);
        $hub->start();
        $failures = 0;
        $failure = static function () use (&$failures): void { ++$failures; };
        $hub->generation('user', 'one')->then(null, $failure);
        $hub->remember('id')->then(null, $failure);
        $hub->generation('user', 'two')->then(null, $failure);
        self::assertSame(1, $failures);
        self::assertCount(1, $commands->calls);
        self::assertSame(1, $hub->metrics()['redis_commands_waiting']);
        $this->loop->advance(10);
        self::assertSame(3, $failures);
        self::assertSame(1, $lost);
        self::assertFalse($hub->ready());
        self::assertSame(0, $hub->metrics()['redis_commands_waiting']);
        $hub->stop();
        self::assertSame([], $this->loop->timers);
    }

    public function testPendingPubSubAcknowledgementsSettleWithoutUnhandledShutdownRejections(): void
    {
        $unhandled = [];
        $previous = set_rejection_handler(static function (\Throwable $error) use (&$unhandled): void {
            $unhandled[] = $error->getMessage();
        });
        try {
            foreach (['subscribe', 'unsubscribe', 'resubscribe', 'peer_close', 'redis_error', 'timeout'] as $scenario) {
                $input = new ThroughStream();
                $stream = new CompositeStream($input, new ThroughStream());
                $subscriber = new PubSubClient($stream);
                $commands = new FakeRedisClient();
                $clients = [$subscriber, $commands];
                $settings = $this->settings(['max_redis_commands' => 1]);
                $hub = new RedisHub($settings, new Config($settings), $this->loop, static function (): void {},
                    static function () use (&$clients): PromiseInterface { return resolve(array_shift($clients)); },
                    static fn (): float => 1.0);
                $hub->start();
                $failures = 0;
                $rejected = static function () use (&$failures): void { ++$failures; };
                $hub->subscribe('channel', 'one', static function (): void {})->then(null, $rejected);
                if (in_array($scenario, ['unsubscribe', 'resubscribe'], true)) {
                    $input->write("*3\r\n$9\r\nsubscribe\r\n$7\r\nchannel\r\n:1\r\n");
                    $hub->unsubscribe('channel', 'one');
                    if ($scenario === 'resubscribe') {
                        $hub->subscribe('channel', 'two', static function (): void {})->then(null, $rejected);
                    }
                }
                $hub->generation('user', 'thread')->then(null, $rejected);
                $hub->remember('id')->then(null, $rejected);
                if ($scenario === 'peer_close') {
                    $stream->close();
                } elseif ($scenario === 'redis_error') {
                    $input->write("-ERR permission denied\r\n");
                } elseif ($scenario === 'timeout') {
                    $this->loop->advance(10);
                }
                $hub->stop();
                self::assertFalse($hub->ready(), $scenario);
                self::assertFalse($stream->isReadable(), $scenario);
                self::assertGreaterThanOrEqual(2, $failures, $scenario);
                self::assertSame(0, $hub->subscriptionCount(), $scenario);
                self::assertSame([], $this->loop->timers, $scenario);
                unset($hub, $subscriber, $commands, $stream, $input, $clients);
                gc_collect_cycles();
                self::assertSame([], $unhandled, $scenario);
            }
        } finally {
            set_rejection_handler($previous);
        }
    }

    public function testPubSubAdapterPreservesAckOrderingAndMessagesDuringUnsubscribe(): void
    {
        $input = new ThroughStream();
        $output = new ThroughStream();
        $subscriber = new PubSubClient(new CompositeStream($input, $output));
        $commands = '';
        $output->on('data', static function (string $chunk) use (&$commands): void { $commands .= $chunk; });
        $messages = [];
        $subscriber->on('message', static function (string $channel, string $payload) use (&$messages): void {
            $messages[] = [$channel, $payload];
        });
        $acks = [];
        $subscriber->subscribe('channel')->then(static function ($ack) use (&$acks): void { $acks[] = $ack; });
        self::assertSame([], $acks);
        self::assertStringContainsString('subscribe', $commands);
        $input->write("*3\r\n$9\r\nsubscr");
        self::assertSame([], $acks);
        $input->write("ibe\r\n$7\r\nchannel\r\n:1\r\n");
        self::assertSame([['subscribe', 'channel', 1]], $acks);
        $subscriber->unsubscribe('channel')->then(static function ($ack) use (&$acks): void { $acks[] = $ack; });
        $input->write("*3\r\n$7\r\nmessage\r\n$7\r\nchannel\r\n$4\r\ndata\r\n");
        self::assertSame([['channel', 'data']], $messages);
        self::assertCount(1, $acks);
        $input->write("*3\r\n$11\r\nunsubscribe\r\n$7\r\nchannel\r\n:0\r\n");
        self::assertSame(['unsubscribe', 'channel', 0], $acks[1]);
        $subscriber->close();
    }
}

final class FakeBackend implements Backend
{
    public array $requests = [];
    public array $closed = [];

    public function request(string $operation, array $payload): PromiseInterface
    {
        $deferred = new Deferred();
        $this->requests[] = [$operation, $payload, $deferred];
        return $deferred->promise();
    }

    public function close(string $authorization): void { $this->closed[] = $authorization; }
}

final class FakeRedis implements RedisGateway
{
    public Deferred $ack;
    public array $listeners = [];
    public array $state = ['messageId' => 'm1', 'status' => 'running'];
    public ?array $rememberRecord = null;
    public ?Deferred $deferredGeneration = null;

    public function __construct() { $this->ack = new Deferred(); }
    public function ready(): bool { return true; }
    public function subscribe(string $channel, string $id, callable $listener): PromiseInterface
    {
        $this->listeners[$id] = $listener;
        return $this->ack->promise();
    }
    public function unsubscribe(string $channel, string $id): void { unset($this->listeners[$id]); }
    public function generation(string $userId, string $threadId): PromiseInterface
    {
        return $this->deferredGeneration?->promise() ?? resolve($this->state);
    }
    public function remember(string $id): PromiseInterface { return resolve($this->rememberRecord); }
    public function emit(string $raw): void { foreach ($this->listeners as $listener) { $listener($raw); } }
}

final class FakeRedisClient extends EventEmitter
{
    public array $calls = [];
    public array $subscribed = [];
    private bool $closed = false;

    public function __call(string $name, array $args): PromiseInterface
    {
        $deferred = new Deferred();
        $this->calls[] = [$name, $args, $deferred];
        return $deferred->promise();
    }

    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        $this->emit('close');
        foreach ($this->calls as $call) { $call[2]->reject(new \RuntimeException('closed')); }
    }

    public function acknowledge(int $index): void
    {
        [$operation, $arguments, $deferred] = $this->calls[$index];
        if ($operation === 'subscribe') {
            $this->subscribed[$arguments[0]] = true;
        } elseif ($operation === 'unsubscribe') {
            unset($this->subscribed[$arguments[0]]);
        } else {
            throw new \LogicException('Expected Pub/Sub command');
        }
        $deferred->resolve(count($this->subscribed));
    }

    public function publish(string $channel, string $payload): void
    {
        if (isset($this->subscribed[$channel])) {
            $this->emit('message', [$channel, $payload]);
        }
    }
}

final class ManualLoop implements LoopInterface
{
    public float $now = 1000.0;
    public array $timers = [];
    private array $ticks = [];

    public function addReadStream($stream, $listener): void { throw new \LogicException('No real I/O in deterministic tests'); }
    public function addWriteStream($stream, $listener): void { throw new \LogicException('No real I/O in deterministic tests'); }
    public function removeReadStream($stream): void {}
    public function removeWriteStream($stream): void {}
    public function addTimer($interval, $callback): TimerInterface { return $this->timer($interval, $callback, false); }
    public function addPeriodicTimer($interval, $callback): TimerInterface { return $this->timer($interval, $callback, true); }
    public function cancelTimer(TimerInterface $timer): void { unset($this->timers[spl_object_id($timer)]); }
    public function futureTick($listener): void { $this->ticks[] = $listener; }
    public function addSignal($signal, $listener): void {}
    public function removeSignal($signal, $listener): void {}
    public function run(): void { $this->tick(); }
    public function stop(): void {}

    public function tick(): void
    {
        $limit = 10000;
        while ($this->ticks !== []) {
            if (--$limit === 0) { throw new \LogicException('Unbounded future ticks'); }
            $ticks = $this->ticks;
            $this->ticks = [];
            foreach ($ticks as $tick) { $tick(); }
        }
    }

    public function advance(float $seconds): void
    {
        $until = $this->now + $seconds;
        $this->tick();
        while ($this->timers !== []) {
            uasort($this->timers, static fn (array $a, array $b): int => $a[1] <=> $b[1]);
            $id = array_key_first($this->timers);
            [$timer, $at] = $this->timers[$id];
            if ($at > $until) { break; }
            $this->now = $at;
            if ($timer->isPeriodic()) { $this->timers[$id][1] += $timer->getInterval(); }
            else { unset($this->timers[$id]); }
            ($timer->getCallback())($timer);
            $this->tick();
        }
        $this->now = $until;
        $this->tick();
    }

    private function timer(float $interval, callable $callback, bool $periodic): TimerInterface
    {
        $timer = new Timer($interval, $callback, $periodic);
        $this->timers[spl_object_id($timer)] = [$timer, $this->now + $interval];
        return $timer;
    }
}
