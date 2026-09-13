<?php

declare(strict_types=1);

namespace App\Test\Fixtures\Sse;

/**
 * Opt-in real-daemon load test: php scripts/sse-load.php --clients=64 --seconds=3
 * Requires PHP CLI, /proc, and redis-server or Docker with redis:7-alpine.
 * Owns all children/Redis data; never connects to production Redis or loads .env.
 * Exit 0: assertions passed; 1: assertion/runtime failure; 2: prerequisite blocker.
 */
use App\Services\ChatGenerationState;
use App\Services\ChatStreamSubscriber;
use App\Services\RememberSession;
use App\Services\Settings;
use Clue\React\Redis\Factory;
use Composer\InstalledVersions;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use RuntimeException;
use Throwable;

final class SseLoad
{
    private array $children = [];
    private array $clients = [];
    private array $samples = [];
    private array $checks = [];
    private array $latencies = [];
    private array $expected = [];
    private array $received = [];
    private array $environment;
    private mixed $redis = null;
    private Browser $browser;
    private string $directory;
    private string $secret;
    private string $prefix;
    private string $url;
    private ?string $container = null;
    private string $phase = 'startup';
    private int $daemonPid = 0;
    private int $serial = 0;
    private int $failures = 0;
    private float $started;
    private float $lastTick;
    private bool $sampling = false;

    public function __construct(private readonly int $count, private readonly int $seconds)
    {
        $this->started = $this->lastTick = microtime(true);
        $this->directory = '/tmp/kilo/sse-load-' . gmdate('Ymd-His') . '-' . getmypid();
        if (! is_dir('/tmp/kilo') || ! mkdir($this->directory, 0700)) {
            throw new RuntimeException('Cannot create /tmp/kilo artifact directory');
        }
        $this->secret = bin2hex(random_bytes(32));
        $this->prefix = 'load-' . getmypid() . ':';
        $this->browser = (new Browser())->withTimeout(5)->withRejectErrorResponse(false);
    }

    public function run(): int
    {
        $exit = 1;
        $error = null;
        $clientStates = [];
        try {
            foreach ([SIGTERM, SIGINT] as $signal) {
                Loop::addSignal($signal, static function (): void { throw new RuntimeException('Load run interrupted'); });
            }
            $this->start();
            $this->exercise();
            $exit = $this->failures === 0 ? 0 : 1;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            $exit = $exception->getCode() === 2 ? 2 : 1;
            if ($exit === 1) {
                $this->check($this->phase . ' completed', false);
            }
            fwrite(STDERR, $error . "\n");
        } finally {
            foreach ($this->clients as $id => $client) {
                $clientStates[$id] = array_intersect_key($client,
                    array_flip(['status', 'snapshots', 'updates', 'first', 'closed', 'headers_ok', 'body_bytes']));
            }
            $this->cleanup();
            $report = [
                'exit' => $exit, 'error' => $error,
                'command' => ['clients' => $this->count, 'seconds_per_plateau' => $this->seconds],
                'settings' => array_filter($this->environment ?? [],
                    static fn ($key) => str_starts_with($key, 'SSE_') && $key !== 'SSE_INTERNAL_SECRET',
                    ARRAY_FILTER_USE_KEY),
                'scope' => 'Real bin/sse, real Redis, in-memory Slim backend, direct loopback HTTP',
                'limitations' => [
                    'No Caddy/Traefik, browser, desktop/mobile or normal/widget validation (plan item 7).',
                    'Fixture credentials are HMAC test tokens, not production JWT/security validation.',
                    'Short requests target both daemon rejection path and fixture health, not SQL business routes.',
                    'RSS from /proc; daemon loop lag only from daemon telemetry, not load-generator timers.',
                    'Daemon telemetry currently samples every 15 seconds; it cannot bound shorter lag/buffer spikes.',
                    'Finite local workload, not a production capacity or unbounded-memory proof.',
                ],
                'checks' => $this->checks,
                'client_states_before_cleanup' => $clientStates,
                'versions' => ['php' => PHP_VERSION,
                    'react/http' => InstalledVersions::getPrettyVersion('react/http'),
                    'clue/redis-react' => InstalledVersions::getPrettyVersion('clue/redis-react')],
                'latency_ms' => array_map($this->distribution(...), $this->latencies),
                'samples' => $this->samples,
                'daemon_telemetry' => $this->telemetry(),
                'daemon_loop_lag_ms' => $this->loopLag(),
                'elapsed_seconds' => microtime(true) - $this->started,
            ];
            file_put_contents($this->directory . '/report.json', json_encode($report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            fwrite(STDOUT, json_encode([
                'exit' => $exit, 'checks' => count($this->checks), 'failures' => $this->failures,
                'artifacts' => $this->directory,
            ], JSON_THROW_ON_ERROR) . "\n");
        }
        return $exit;
    }

    private function start(): void
    {
        $root = Settings::getAppRoot();
        if (! is_file($root . '/bin/sse')) {
            throw new RuntimeException('Prerequisite: real bin/sse does not exist yet', 2);
        }
        if (! is_dir('/proc/self')) {
            throw new RuntimeException('Prerequisite: Linux /proc required', 2);
        }
        // Production Config currently pins the backend port; never evict an existing listener.
        $guard = @stream_socket_server('tcp://127.0.0.1:8082', $errno, $message);
        if ($guard === false) {
            throw new RuntimeException('Prerequisite: fixture port 127.0.0.1:8082 is occupied', 2);
        }
        fclose($guard);
        $redisPort = $this->port();
        $daemonPort = $this->port();
        $this->url = 'http://127.0.0.1:' . $daemonPort;
        // Supply every mandatory Settings::load() variable without inheriting deployment credentials.
        $this->environment = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp/kilo',
            'BASE_URL' => $this->url, 'OPENAPI_URL' => 'http://127.0.0.1:1', 'OPENAPI_MODEL' => 'fixture',
            'OPENID_WELLKNOWN_URL' => 'http://127.0.0.1:1', 'OPENID_CLIENT_ID' => 'fixture',
            'SESSION_JWT_SECRET' => $this->secret, 'SSE_INTERNAL_SECRET' => $this->secret,
            'SSE_LISTEN' => '127.0.0.1:' . $daemonPort, 'SSE_BACKEND' => 'http://127.0.0.1:8082',
            'SSE_DURATION' => (string) max(120, $this->seconds * 12 + 60),
            'SSE_FIXTURE_SHORT_DURATION' => '6',
            'SSE_CHECK_INTERVAL' => '2', 'SSE_KEEPALIVE' => '1', 'SSE_HTTP_TIMEOUT' => '4',
            'SSE_MAX_CONNECTIONS' => (string) ($this->count + 8),
            'SSE_MAX_HTTP_REQUESTS' => '16', 'SSE_MAX_PENDING_EVENTS' => '64',
            'SSE_MAX_CLIENT_BUFFER' => '262144', 'SSE_MAX_GLOBAL_BUFFER' => '8388608',
            'SSE_WRITE_TIMEOUT' => '2', 'SSE_SHUTDOWN_TIMEOUT' => '2', 'SSE_MAX_REDIS_COMMANDS' => '64',
            'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => (string) $redisPort,
            'REDIS_DATABASE' => '0', 'REDIS_PREFIX' => $this->prefix, 'REDIS_TIMEOUT' => '1',
            'OTEL_SDK_DISABLED' => 'true',
        ];
        $binary = $this->executable('redis-server');
        if ($binary !== null) {
            $this->spawn('redis', [$binary, '--bind', '127.0.0.1', '--port', (string) $redisPort,
                '--save', '', '--appendonly', 'no', '--dir', $this->directory]);
        } else {
            $docker = $this->executable('docker');
            if ($docker === null) {
                throw new RuntimeException('Prerequisite: neither redis-server nor Docker is available', 2);
            }
            $this->container = 'claire-sse-load-' . getmypid() . '-' . bin2hex(random_bytes(4));
            $this->spawn('redis', [$docker, 'run', '--rm', '--pull=never', '--name', $this->container,
                '-p', '127.0.0.1:' . $redisPort . ':6379', 'redis:7-alpine',
                'redis-server', '--save', '', '--appendonly', 'no']);
        }
        $this->wait(function () use ($redisPort): bool {
            try {
                $this->redis = $this->await((new Factory())->createClient('redis://127.0.0.1:' . $redisPort . '?timeout=0.2'));
                return $this->await($this->redis->ping()) === 'PONG';
            } catch (Throwable) {
                return false;
            }
        }, 10, 'Disposable Redis unavailable; inspect redis.log (Docker socket/image may be unavailable)', 2);
        $this->spawn('backend', [PHP_BINARY, $root . '/test/fixtures/sse/backend.php']);
        $this->wait(fn () => $this->listening(8082), 5, 'Slim fixture did not start; inspect backend.log');
        $this->spawn('daemon', [PHP_BINARY, $root . '/bin/sse']);
        $this->daemonPid = proc_get_status($this->children['daemon'])['pid'];
        $this->wait(fn () => $this->listening($daemonPort), 5, 'Real daemon did not start; inspect daemon.log');
        $this->wait(fn () => $this->redisConnections() >= 3,
            5, 'Real daemon did not establish shared Redis connections');
        $this->lastTick = microtime(true);
        Loop::addPeriodicTimer(0.1, function (): void {
            $now = microtime(true);
            $this->latencies[$this->phase . '.generator_loop_lag'][] = max(0, ($now - $this->lastTick - 0.1) * 1000);
            $this->lastTick = $now;
        });
        Loop::addPeriodicTimer(0.5, function (): void {
            if (! $this->sampling) {
                return;
            }
            $this->sample();
            $this->probe($this->url . '/__load_short_request', 'daemon_short', 404);
            $this->probe('http://127.0.0.1:8082/health', 'backend_short', 200);
        });
        $this->sampling = true;
        $this->sample();
    }

    private function exercise(): void
    {
        $this->phase = 'isolation';
        $groups = [
            ['alice', 'tab-a', 'thread-a'], ['alice', 'tab-a', 'thread-a'],
            ['bob', 'tab-a', 'thread-a'], ['alice', 'tab-b', 'thread-a'],
            ['alice', 'tab-a', 'thread-b'],
        ];
        $ids = [];
        foreach ($groups as $group) {
            $ids[] = $this->open(...$group);
        }
        $this->wait(fn () => $this->snapshots($ids), 6, 'Isolation clients did not receive initial snapshots');
        $this->check('initial SSE headers and snapshot-first', array_all($ids,
            fn ($id) => $this->clients[$id]['headers_ok'] && $this->clients[$id]['first'] === 'chat.snapshot'));
        $channel = $this->channel('alice', 'tab-a');
        $subscriptions = $this->await($this->redis->pubsub('NUMSUB', $channel));
        $this->check('one Redis subscription for three same-channel sockets', ($subscriptions[1] ?? null) === 1);
        $this->publish('alice', 'tab-a', 'thread-a', [$ids[0], $ids[1]]);
        $this->publish('bob', 'tab-a', 'thread-a', [$ids[2]]);
        $this->publish('alice', 'tab-b', 'thread-a', [$ids[3]]);
        $this->publish('alice', 'tab-a', 'thread-b', [$ids[4]]);
        $this->publish('alice', 'tab-a', 'thread-a', [], 100, 'stale-message');
        $this->publish('nobody', 'absent', 'thread-a', []);
        $this->spin(1);
        $this->verifyDelivery('fanout, user/tab/thread and stale-generation isolation');
        foreach ($ids as $id) {
            $this->close($id);
        }
        $this->spin(0.5);

        $this->phase = 'expired-admission';
        $expired = $this->open('alice', 'expired', 'thread-a', false, time() - 1);
        $this->wait(fn () => $this->clients[$expired]['status'] !== null, 5, 'Expired admission hung');
        $this->check('expired fixture credential refused', $this->clients[$expired]['status'] === 401);
        $this->close($expired);

        $this->phase = 'short-deadline';
        $expires = time() + 2;
        $bounded = $this->open('alice', 'deadline', 'thread-a', false, $expires);
        $this->wait(fn () => $this->snapshots([$bounded]), 5, 'Short-deadline handshake failed');
        $this->spin(2.2);
        $this->check('admitted socket outlives fixture credential TTL', ! $this->clients[$bounded]['closed']);
        $this->await($this->redis->publish($this->channel('alice', 'deadline'), json_encode([
            'version' => 1, 'event' => 'chat.snapshot', 'threadId' => 'thread-a',
            'payload' => ['sessionId' => 'deadline', 'threadId' => 'thread-a'],
        ], JSON_THROW_ON_ERROR)));
        $this->wait(fn () => $this->clients[$bounded]['snapshots'] >= 2, 2,
            'Authorized snapshot after credential expiry did not arrive');
        $retry = $this->open('alice', 'deadline', 'thread-a', false, $expires);
        $this->wait(fn () => $this->clients[$retry]['status'] !== null, 2, 'Expired reconnect hung');
        $this->check('same expired credential cannot reconnect', $this->clients[$retry]['status'] === 401);
        $this->close($retry);
        $this->wait(fn () => $this->clients[$bounded]['closed'], 5, 'Configured six-second deadline did not close socket');
        $this->check('short authorized deadline enforced after later snapshot', true);

        $this->phase = 'remember';
        foreach (['revoked', 'wrong-user', 'wrong-expiry', 'natural-expiry'] as $scenario) {
            $remember = ['id' => bin2hex(random_bytes(32)),
                'expires' => time() + ($scenario === 'natural-expiry' ? 3 : 60)];
            $key = RememberSession::rememberKey($this->prefix, $remember['id']);
            $record = ['user_id' => 'remembered', 'expires' => $remember['expires']];
            $this->await($this->redis->setex($key, 60, json_encode($record, JSON_THROW_ON_ERROR)));
            $remembered = $this->open('remembered', $scenario, 'thread-a', remember: $remember);
            $this->wait(fn () => $this->snapshots([$remembered]), 5, 'Remember handshake failed');
            $started = microtime(true);
            if ($scenario === 'revoked') {
                $this->await($this->redis->del($key));
            } elseif ($scenario === 'wrong-user' || $scenario === 'wrong-expiry') {
                $record[$scenario === 'wrong-user' ? 'user_id' : 'expires'] =
                    $scenario === 'wrong-user' ? 'other-user' : $remember['expires'] + 1;
                $this->await($this->redis->setex($key, 60, json_encode($record, JSON_THROW_ON_ERROR)));
            }
            $this->wait(fn () => $this->clients[$remembered]['closed'], 6, 'Remember check did not close: ' . $scenario);
            $this->latencies['remember.' . $scenario][] = (microtime(true) - $started) * 1000;
            $this->check('real Redis remember ' . $scenario . ' closes admitted stream', true);
            $this->await($this->redis->del($key));
        }

        $this->phase = 'redis-loss';
        foreach (['pubsub', 'normal'] as $type) {
            $before = $this->open('outage', $type, 'thread-a');
            $this->wait(fn () => $this->snapshots([$before]), 5, 'Pre-outage handshake failed');
            // This Redis instance belongs exclusively to the test; SKIPME preserves its driver.
            $killed = $this->await($this->redis->client('KILL', 'TYPE', $type, 'SKIPME', 'yes'));
            $this->check('Redis outage targets one shared ' . $type . ' connection', $killed === 1);
            $this->wait(fn () => $this->clients[$before]['closed'], 3, 'Redis loss left stream open');
            $this->wait(fn () => $this->redisConnections() === 3, 6, 'Redis shared connections did not recover');
            $after = $this->open('outage', $type, 'thread-a');
            $this->wait(fn () => $this->snapshots([$after]), 5, 'Post-outage handshake failed');
            $this->publish('outage', $type, 'thread-a', [$after]);
            $this->spin(0.2);
            $this->verifyDelivery('Redis ' . $type . ' loss closes old context and restores new live');
            $this->close($after);
            $this->spin(0.2);
        }

        $live = [];
        for ($step = 1; $step <= 4; ++$step) {
            $this->phase = 'inactive-' . $step;
            $target = (int) ceil($this->count * $step / 4);
            while (count($live) < $target) {
                $live[] = $this->open('load', 'tab-' . count($live), 'thread-load');
                // Ramp admission separately from the later intentional reconnect burst.
                $this->spin(0.03);
            }
            $this->wait(fn () => $this->snapshots($live), 8, 'Inactive ramp admission failed');
            $this->spin($this->seconds);
        }
        $this->check('Redis connections independent of browser count',
            $this->redisConnections() === 3);
        for ($step = 1; $step <= 4; ++$step) {
            $this->phase = 'active-' . $step;
            $active = array_slice($live, 0, (int) ceil($this->count * $step / 4));
            $timer = Loop::addPeriodicTimer(0.2, function () use ($active): void {
                foreach ($active as $id) {
                    $client = $this->clients[$id];
                    $this->publish($client['user'], $client['session'], $client['thread'], [$id]);
                }
            });
            $this->spin($this->seconds);
            Loop::cancelTimer($timer);
            $this->spin(0.3);
            $this->verifyDelivery('active ramp ' . $step . ' exact delivery');
        }

        $this->phase = 'connection-limit';
        $extra = [];
        for ($i = 0; $i < 8; ++$i) {
            $extra[] = $this->open('limit', 'tab-' . $i, 'thread-limit');
            $this->spin(0.05);
        }
        $this->wait(fn () => $this->snapshots($extra), 6, 'Could not fill configured connection limit');
        $overflow = $this->open('limit', 'overflow', 'thread-limit');
        $this->wait(fn () => $this->clients[$overflow]['status'] !== null, 5, 'Capacity refusal hung');
        $this->check('connection cap produces controlled HTTP refusal',
            in_array($this->clients[$overflow]['status'], [429, 503], true));
        foreach ([...$extra, $overflow] as $id) {
            $this->close($id);
        }
        $this->spin(0.5);

        $this->phase = 'slow-client';
        $slow = $this->open('slow', 'tab-slow', 'thread-slow', true);
        $this->wait(fn () => $this->snapshots([$slow]), 5, 'Slow client handshake failed');
        // Pause reads only after headers/snapshot. Enough valid frames to fill kernel receive buffers too.
        for ($i = 0; $i < 512; ++$i) {
            $this->publish('slow', 'tab-slow', 'thread-slow', [], 65536);
            if ($i % 8 === 0) {
                $client = $this->clients[$live[0]];
                $this->publish($client['user'], $client['session'], $client['thread'], [$live[0]]);
            }
            $this->spin(0.01);
        }
        $this->spin(3);
        $numsub = $this->await($this->redis->pubsub('NUMSUB', $this->channel('slow', 'tab-slow')));
        $this->check('non-reading client evicted and subscription released', ($numsub[1] ?? null) === 0);
        $this->verifyDelivery('healthy delivery while slow client fills transport buffers');
        $this->close($slow);

        $this->phase = 'oversized';
        $large = $this->open('large', 'tab-large', 'thread-large');
        $this->wait(fn () => $this->snapshots([$large]), 5, 'Oversized test handshake failed');
        $this->publish('large', 'tab-large', 'thread-large', [], 300000);
        $this->spin(1);
        $this->check('oversized event closes affected socket', $this->clients[$large]['closed']);
        $this->check('oversized event does not close healthy sockets', array_all($live,
            fn ($id) => ! $this->clients[$id]['closed']));
        $this->close($large);

        $this->phase = 'reconnect-burst';
        foreach ($live as $id) {
            $this->close($id);
        }
        $this->spin(0.5);
        $live = [];
        for ($i = 0; $i < $this->count; ++$i) {
            $live[] = $this->open('load', 'tab-' . $i, 'thread-load');
        }
        $this->spin(5);
        $this->check('burst yields snapshots or controlled admission refusal', array_all($live,
            fn ($id) => $this->clients[$id]['snapshots'] > 0
                || in_array($this->clients[$id]['status'], [429, 503], true)));
        $refused = array_filter($live, fn ($id) => $this->clients[$id]['snapshots'] === 0);
        $this->checks[] = ['name' => 'burst observed refusals', 'value' => count($refused)];
        foreach ($refused as $id) {
            $client = $this->clients[$id];
            $this->close($id);
            $replacement = $this->open($client['user'], $client['session'], $client['thread']);
            $live[array_search($id, $live, true)] = $replacement;
            $this->spin(0.05);
        }
        $this->wait(fn () => $this->snapshots($live), 8, 'Burst refused clients did not recover on fresh admission');
        $this->check('reconnect starts with fresh snapshot and no Pub/Sub replay', array_all($live,
            fn ($id) => $this->clients[$id]['first'] === 'chat.snapshot' && $this->clients[$id]['updates'] === 0));
        foreach ($live as $id) {
            $client = $this->clients[$id];
            $this->publish($client['user'], $client['session'], $client['thread'], [$id]);
        }
        $this->spin(1);
        $this->verifyDelivery('post-burst publication delivery');
        foreach ($live as $id) {
            $this->close($id);
        }
        $this->spin(1);
        $this->phase = 'shutdown';
        $shutdown = $this->open('shutdown', 'tab', 'thread');
        $this->wait(fn () => $this->snapshots([$shutdown]), 5, 'Shutdown handshake failed');
        $this->sampling = false;
        proc_terminate($this->children['daemon'], SIGTERM);
        $this->wait(fn () => ! proc_get_status($this->children['daemon'])['running'], 5, 'SIGTERM deadline exceeded');
        $this->check('daemon exits successfully on SIGTERM', proc_get_status($this->children['daemon'])['exitcode'] === 0);
        $this->spin(0.2);
        $this->check('SIGTERM closes existing SSE socket', $this->clients[$shutdown]['closed']);
        $this->check('SIGTERM releases daemon Redis connections',
            $this->redisConnections() === 1);
        $this->check('daemon emits no fatal errors or unhandled promise rejections',
            preg_match('/Unhandled promise rejection|Fatal error|Uncaught /',
                file_get_contents($this->directory . '/daemon.log')) === 0);
        $stats = $this->await($this->browser->post('http://127.0.0.1:8082/stats', [
            'X-Claire-Sse-Secret' => $this->secret,
        ]));
        $counts = json_decode((string) $stats->getBody(), true, 16, JSON_THROW_ON_ERROR);
        $this->check('all fixture authorizations released after shutdown', ($counts['authorizations'] ?? null) === 0);
        $this->checks[] = ['name' => 'fixture backend request counts', 'value' => $counts];
        $telemetry = $this->telemetry();
        $this->check('daemon loop-lag telemetry emitted', $this->loopLag() !== null);
        $this->check('sampled daemon buffers stay within configured global budget', $telemetry !== []
            && array_all($telemetry, static fn ($record) => isset($record['buffer_bytes'])
                && $record['buffer_bytes'] >= 0 && $record['buffer_bytes'] <= 8388608));
    }

    /** @param array{id: string, expires: int}|null $remember */
    private function open(
        string $user,
        string $session,
        string $thread,
        bool $slow = false,
        ?int $expires = null,
        ?array $remember = null,
    ): int
    {
        $id = ++$this->serial;
        $this->redis->hmset(ChatGenerationState::stateKey($this->prefix, $user, $thread),
            'messageId', 'load-message', 'status', 'running')->then(null,
                function (): void { $this->check('seed generation state', false); });
        $claims = base64_encode(json_encode([
            'userId' => $user, 'sessionId' => $session, 'threadId' => $thread,
            'expires' => $expires ?? time() + 30,
            'remember' => $remember,
        ], JSON_THROW_ON_ERROR));
        $credential = $claims . '.' . hash_hmac('sha256', $claims, $this->secret);
        $this->clients[$id] = [
            'user' => $user, 'session' => $session, 'thread' => $thread,
            'status' => null, 'snapshots' => 0, 'updates' => 0, 'first' => null,
            'closed' => false, 'headers_ok' => false, 'stream' => null, 'buffer' => '', 'body_bytes' => 0,
        ];
        $start = microtime(true);
        $phase = $this->phase;
        $promise = $this->browser->requestStreaming('GET', $this->url . '/brain/stream?' . http_build_query([
            'threadId' => $thread, 'sessionId' => $session,
        ]), ['X-Claire-Auth' => $credential, 'Accept' => 'text/event-stream',
            'Last-Event-ID' => 'unsupported-load-cursor']);
        $this->clients[$id]['request'] = $promise;
        $promise->then(function (ResponseInterface $response) use ($id, $start, $phase, $slow): void {
            $client = &$this->clients[$id];
            $client['status'] = $response->getStatusCode();
            $client['headers_ok'] = str_contains($response->getHeaderLine('Content-Type'), 'text/event-stream')
                && str_contains($response->getHeaderLine('Cache-Control'), 'no-store');
            $this->latencies[$phase . '.headers'][] = (microtime(true) - $start) * 1000;
            $stream = $response->getBody();
            if (! $stream instanceof ReadableStreamInterface) {
                throw new RuntimeException('Expected streaming React response');
            }
            $client['stream'] = $stream;
            $stream->on('close', function () use ($id): void { $this->clients[$id]['closed'] = true; });
            $stream->on('error', function () use ($id): void { $this->clients[$id]['closed'] = true; });
            if ($client['status'] !== 200) {
                $stream->close();
                return;
            }
            $stream->on('data', function (string $chunk) use ($id, $start, $phase, $slow, $stream): void {
                $client = &$this->clients[$id];
                $client['body_bytes'] += strlen($chunk);
                $client['buffer'] .= str_replace("\r\n", "\n", $chunk);
                while (($end = strpos($client['buffer'], "\n\n")) !== false) {
                    $frame = substr($client['buffer'], 0, $end);
                    $client['buffer'] = substr($client['buffer'], $end + 2);
                    if (! preg_match('/^event:\s*(\S+)/m', $frame, $match)) {
                        continue;
                    }
                    $event = $match[1];
                    $client['first'] ??= $event;
                    if ($event === 'chat.snapshot') {
                        if (++$client['snapshots'] === 1) {
                            $this->latencies[$phase . '.snapshot'][] = (microtime(true) - $start) * 1000;
                            if ($slow) {
                                $stream->pause();
                            }
                        }
                    } elseif ($event === 'chat.assistant.update') {
                        ++$client['updates'];
                        preg_match_all('/^data: ?(.*)$/m', $frame, $data);
                        $payload = json_decode(implode("\n", $data[1]), true, 32, JSON_THROW_ON_ERROR);
                        $marker = $payload['loadMarker'] ?? null;
                        if (is_string($marker)) {
                            $this->received[$marker][] = $id;
                            $this->latencies[$this->phase . '.delivery'][] = (microtime(true) - $payload['sentAt']) * 1000;
                        }
                    }
                }
            });
        }, function () use ($id): void {
            // Never write an exception containing the request/credential to artifacts.
            $this->clients[$id]['status'] = 0;
            $this->clients[$id]['closed'] = true;
        });
        return $id;
    }

    private function publish(string $user, string $session, string $thread, array $recipients,
        int $bytes = 100, string $message = 'load-message'): void
    {
        $marker = 'event-' . ++$this->serial;
        // Slow/oversized traffic has no delivery expectation; the subscription/close assertions cover it.
        if ($user !== 'slow' && $user !== 'large') {
            $this->expected[$marker] = $recipients;
        }
        $this->redis->publish($this->channel($user, $session), json_encode([
            'version' => 1, 'event' => 'chat.assistant.update', 'threadId' => $thread,
            'payload' => [
                'threadId' => $thread, 'sessionId' => $session, 'messageId' => $message,
                'loadMarker' => $marker, 'sentAt' => microtime(true), 'text' => str_repeat('x', $bytes),
            ],
        ], JSON_THROW_ON_ERROR))->then(function ($result) use ($user): void {
            if ($user === 'nobody') {
                $this->check('publish with zero subscribers succeeds', $result === 0);
            }
        }, function (): void { $this->check('Redis publication completed', false); });
    }

    private function verifyDelivery(string $name): void
    {
        $mismatches = 0;
        foreach ($this->expected as $marker => $expected) {
            $received = $this->received[$marker] ?? [];
            sort($expected);
            sort($received);
            if ($received !== $expected) {
                ++$mismatches;
            }
        }
        $this->check($name, $mismatches === 0, ['events' => count($this->expected), 'mismatches' => $mismatches]);
        $this->expected = $this->received = [];
    }

    private function probe(string $url, string $name, int $expected): void
    {
        $start = microtime(true);
        $phase = $this->phase;
        $this->browser->get($url)->then(function (ResponseInterface $response) use ($start, $phase, $name, $expected): void {
            $this->latencies[$phase . '.' . $name][] = (microtime(true) - $start) * 1000;
            if ($response->getStatusCode() !== $expected) {
                $this->check($name . ' status', false, ['status' => $response->getStatusCode()]);
            }
        }, function () use ($name): void { $this->check($name . ' completed', false); });
    }

    private function sample(): void
    {
        $status = @file_get_contents('/proc/' . $this->daemonPid . '/status');
        preg_match('/^VmRSS:\s+(\d+) kB/m', $status ?: '', $rss);
        $sample = [
            'seconds' => microtime(true) - $this->started, 'phase' => $this->phase,
            'daemon_rss_kib' => isset($rss[1]) ? (int) $rss[1] : null,
            'live_readers' => count(array_filter($this->clients,
                static fn ($client) => $client['snapshots'] > 0 && ! $client['closed'])),
        ];
        \React\Promise\all([$this->redis->info('clients'), $this->redis->pubsub('CHANNELS', $this->prefix . '*')])
            ->then(function (array $values) use ($sample): void {
                preg_match('/connected_clients:(\d+)/', $values[0], $count);
                $this->samples[] = [...$sample,
                    'redis_connections_including_harness' => (int) ($count[1] ?? 0),
                    'redis_pubsub_channels' => count($values[1]),
                ];
            }, function (): void { $this->check('Redis metrics completed', false); });
    }

    private function snapshots(array $ids): bool
    {
        return array_all($ids, fn ($id) => $this->clients[$id]['snapshots'] > 0 && ! $this->clients[$id]['closed']);
    }

    private function close(int $id): void
    {
        $this->clients[$id]['stream']?->close();
        $this->clients[$id]['request']->cancel();
        $this->clients[$id]['closed'] = true;
        $this->clients[$id]['buffer'] = '';
    }

    private function channel(string $user, string $session): string
    {
        return ChatStreamSubscriber::channelName($this->prefix, ChatStreamSubscriber::scope($user, $session));
    }

    private function check(string $name, bool $passed, array $details = []): void
    {
        $this->checks[] = ['name' => $name, 'passed' => $passed, ...$details];
        if (! $passed) {
            ++$this->failures;
            fwrite(STDERR, 'FAIL: ' . $name . "\n");
        }
    }

    private function distribution(array $values): array
    {
        sort($values);
        $count = count($values);
        return ['count' => $count, 'p50' => $values[(int) floor(($count - 1) * 0.5)],
            'p95' => $values[(int) floor(($count - 1) * 0.95)],
            'p99' => $values[(int) floor(($count - 1) * 0.99)], 'max' => $values[$count - 1]];
    }

    private function spin(float $seconds): void
    {
        Loop::addTimer($seconds, static fn () => Loop::stop());
        Loop::run();
    }

    private function await(PromiseInterface $promise): mixed
    {
        $done = false;
        $value = null;
        $error = null;
        $promise->then(static function ($result) use (&$done, &$value): void {
            $value = $result;
            $done = true;
        }, static function (Throwable $exception) use (&$done, &$error): void {
            $error = $exception;
            $done = true;
        });
        $this->wait(static function () use (&$done): bool { return $done; },
            5, 'Load-driver asynchronous operation timed out');
        if ($error !== null) {
            throw $error;
        }
        return $value;
    }

    private function redisConnections(): int
    {
        preg_match('/connected_clients:(\d+)/', $this->await($this->redis->info('clients')), $count);
        return (int) ($count[1] ?? 0);
    }

    private function wait(callable $condition, float $seconds, string $error, int $code = 1): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            if ($condition()) {
                return;
            }
            $this->spin(0.05);
        } while (microtime(true) < $deadline);
        throw new RuntimeException($error, $code);
    }

    private function port(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException('Cannot allocate loopback port', 2);
        }
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        return $port;
    }

    private function listening(int $port): bool
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.05);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    private function executable(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            if (is_executable($directory . '/' . $name)) {
                return $directory . '/' . $name;
            }
        }
        return null;
    }

    private function spawn(string $name, array $command): void
    {
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->directory . '/' . $name . '.log', 'a'],
            2 => ['file', $this->directory . '/' . $name . '.log', 'a'],
        ], $pipes, Settings::getAppRoot(), $this->environment);
        if ($process === false) {
            throw new RuntimeException('Cannot start ' . $name);
        }
        $this->children[$name] = $process;
    }

    private function telemetry(): array
    {
        $records = [];
        if (! is_file($this->directory . '/daemon.log')) {
            return $records;
        }
        foreach (file($this->directory . '/daemon.log', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            try {
                $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($record)) {
                    $records[] = $record;
                }
            } catch (JsonException) {
                // Raw diagnostic lines remain in daemon.log, not interpreted as measurements.
            }
        }
        return $records;
    }

    private function loopLag(): ?array
    {
        $values = [];
        foreach ($this->telemetry() as $record) {
            if (isset($record['loop_lag_seconds']) && is_numeric($record['loop_lag_seconds'])) {
                $values[] = $record['loop_lag_seconds'] * 1000;
            }
        }
        return $values === [] ? null : $this->distribution($values);
    }

    private function cleanup(): void
    {
        $this->sampling = false;
        foreach (array_keys($this->clients) as $id) {
            $this->close($id);
        }
        foreach (array_reverse($this->children, true) as $name => $process) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGTERM);
                $deadline = microtime(true) + 4;
                while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                    usleep(50000);
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, SIGKILL);
                }
            }
            proc_close($process);
        }
        if ($this->container !== null) {
            $process = proc_open(['docker', 'rm', '-f', $this->container], [
                0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory . '/cleanup.log', 'a'],
                2 => ['file', $this->directory . '/cleanup.log', 'a'],
            ], $pipes);
            if (is_resource($process)) {
                proc_close($process);
            }
        }
        $this->redis?->close();
    }
}
