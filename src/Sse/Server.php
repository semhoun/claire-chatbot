<?php

declare(strict_types=1);

namespace App\Sse;

use App\Services\ChatStreamSubscriber;
use App\Services\CorsHeaders;
use App\Services\Settings;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\Message\Response;
use React\Promise\Promise;

final class Server
{
    /** @var array<int|string, Connection> */
    private array $connections = [];
    /** @var array<int|string, \React\Promise\PromiseInterface> */
    private array $admissions = [];
    private bool $stopping = false;
    private readonly CorsHeaders $cors;
    private readonly string $path;
    private readonly string $prefix;
    private int $sequence = 0;
    /** @var array<string, int> */
    private array $reasons = [];

    public function __construct(
        Settings $settings,
        private readonly Config $config,
        private readonly LoopInterface $loop,
        private readonly Backend $backend,
        private readonly RedisGateway $redis,
        private readonly Budget $budget,
        private readonly \Closure $clock,
    ) {
        $this->cors = new CorsHeaders($settings);
        $this->path = rtrim((string) parse_url($settings->get('base_url'), PHP_URL_PATH), '/') . '/brain/stream';
        $this->prefix = $settings->get('redis.prefix');
    }

    public function handle(ServerRequestInterface $request, ?\Closure $disconnect = null): mixed
    {
        $respond = fn (int $status, mixed $body = '') => $this->cors->apply($request, new Response($status, [
            'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer',
            'Content-Type' => $status === 200 ? 'text/event-stream' : 'text/plain; charset=utf-8',
            'Connection' => 'close', 'X-Accel-Buffering' => 'no',
        ], $body));
        if ($request->getUri()->getPath() !== $this->path) {
            return $respond(404);
        }
        if ($request->getMethod() === 'OPTIONS') {
            return $respond(204);
        }
        if ($request->getMethod() !== 'GET') {
            return $respond(405)->withHeader('Allow', 'GET, OPTIONS');
        }
        if ($request->hasHeader('Transfer-Encoding')
            || ! in_array($request->getHeaderLine('Content-Length'), ['', '0'], true)) {
            return $respond(400);
        }
        $query = $request->getUri()->getQuery();
        if (strlen($query) > 32768 || count($request->getHeader('X-Claire-Auth')) > 1) {
            return $respond(400);
        }
        $parameters = [];
        foreach (explode('&', $query) as $part) {
            $pair = explode('=', $part, 2);
            $name = urldecode($pair[0]);
            if (count($pair) !== 2 || ! in_array($name, ['threadId', 'sessionId', 'token'], true)
                || isset($parameters[$name])) {
                return $respond(400);
            }
            $parameters[$name] = urldecode($pair[1]);
        }
        foreach (['threadId', 'sessionId'] as $name) {
            $value = $parameters[$name] ?? '';
            if ($value === '' || strlen($value) > 512 || trim($value) !== $value
                || preg_match('/[\x00-\x1f\x7f]/', $value) || ! preg_match('//u', $value)) {
                return $respond(400);
            }
        }
        $header = $request->getHeaderLine('X-Claire-Auth');
        $credential = $header !== '' ? $header : ($parameters['token'] ?? '');
        if ($credential === '') {
            return $respond(401);
        }
        if (strlen($credential) > 24576 || ($header !== '' && isset($parameters['token']))
            || preg_match('/[\x00-\x20\x7f]/', $credential) || ! preg_match('//u', $credential)) {
            return $respond(400);
        }
        $input = [
            'credential' => $credential, 'credentialType' => $header !== '' ? 'header' : 'capability',
            'path' => $request->getUri()->getPath(), 'method' => 'GET',
            'threadId' => $parameters['threadId'], 'sessionId' => $parameters['sessionId'],
        ];
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 16384) {
            return $respond(400);
        }
        if ($this->stopping || ! $this->redis->ready()
            || count($this->connections) + count($this->admissions) >= $this->config->get('max_connections')
            || count($this->admissions) >= $this->config->get('max_http_requests')) {
            return $respond(503)->withHeader('Retry-After', '1');
        }
        ++$this->sequence;
        $id = (string) $this->sequence;
        $pending = $this->backend->request('open', $input);
        $this->admissions[$id] = $pending;
        return new Promise(function ($resolve) use ($pending, $id, $parameters, $respond, $disconnect): void {
            $pending->then(function (array $authorization) use ($id, $parameters, $respond, $resolve, $disconnect): void {
                $admitted = isset($this->admissions[$id]);
                unset($this->admissions[$id]);
                $opaque = $authorization['authorization'] ?? null;
                if (! $admitted || $this->stopping || ! $this->redis->ready()
                    || ! $this->validAuthorization($authorization, $parameters)) {
                    if (is_string($opaque) && $opaque !== '') {
                        $this->backend->close($opaque);
                    }
                    $resolve($respond(503));
                    return;
                }
                $channel = ChatStreamSubscriber::channelName(
                    $this->prefix,
                    ChatStreamSubscriber::scope($authorization['userId'], $authorization['sessionId'])
                );
                $streaming = false;
                $connection = new Connection(
                    $id,
                    $channel,
                    $authorization,
                    $this->config,
                    $this->loop,
                    $this->backend,
                    $this->redis,
                    $this->budget,
                    $this->clock,
                    function (string $closedId, string $reason) use ($disconnect, &$streaming): void {
                        unset($this->connections[$closedId]);
                        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
                        if ($streaming && $disconnect !== null) {
                            $disconnect();
                        }
                    },
                    transportBytes: 65536
                );
                $this->connections[$id] = $connection;
                $connection->start()->then(
                    function (Output $output) use ($resolve, $respond, &$streaming): void {
                        $streaming = true;
                        $resolve($respond(200, $output));
                        // HTTP wraps the source in a chunk encoder before installing its data listener.
                        $this->loop->futureTick($output->resume(...));
                    },
                    static fn () => $resolve($respond(503))
                );
            }, function (\Throwable $error) use ($id, $respond, $resolve): void {
                unset($this->admissions[$id]);
                $this->reasons['backend_open_failed'] = ($this->reasons['backend_open_failed'] ?? 0) + 1;
                $resolve($respond(in_array($error->getCode(), [400, 401, 403], true) ? $error->getCode() : 503));
            });
        }, function () use ($pending, $id): void {
            unset($this->admissions[$id]);
            ($this->connections[$id] ?? null)?->close('handshake_cancelled');
            $pending->cancel();
            throw new \RuntimeException('SSE handshake cancelled');
        });
    }

    public function unavailable(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close('redis_lost');
        }
    }

    public function stop(): void
    {
        $this->stopping = true;
        foreach ($this->admissions as $pending) {
            $pending->cancel();
        }
        $this->admissions = [];
        foreach ($this->connections as $connection) {
            $connection->close('shutdown');
        }
    }

    /** @return array{connections: int, admissions: int, buffer_bytes: int, closures: array<string, int>} */
    public function metrics(): array
    {
        return ['connections' => count($this->connections), 'admissions' => count($this->admissions),
            'buffer_bytes' => $this->budget->bytes(), 'closures' => $this->reasons,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $parameters
     */
    private function validAuthorization(array $data, array $parameters): bool
    {
        foreach (['authorization', 'userId', 'threadId', 'sessionId'] as $key) {
            if (! is_string($data[$key] ?? null) || $data[$key] === '' || strlen($data[$key]) > 4096) {
                return false;
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $data['authorization']) !== 1
            || $data['threadId'] !== $parameters['threadId'] || $data['sessionId'] !== $parameters['sessionId']
            || (! is_int($data['openedAt'] ?? null) && ! is_float($data['openedAt'] ?? null))
            || (! is_int($data['deadline'] ?? null) && ! is_float($data['deadline'] ?? null))
            || ! is_finite((float) $data['openedAt']) || ! is_finite((float) $data['deadline'])
            || $data['openedAt'] > ($this->clock)() || $data['deadline'] <= ($this->clock)()
            || $data['deadline'] <= $data['openedAt']
            || $data['deadline'] - $data['openedAt'] > $this->config->get('max_duration')
            || ! array_key_exists('remember', $data)) {
            return false;
        }
        $remember = $data['remember'];
        return $remember === null || (is_array($remember) && is_string($remember['id'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $remember['id']) === 1
            && is_int($remember['expires'] ?? null) && $remember['expires'] > ($this->clock)());
    }
}
