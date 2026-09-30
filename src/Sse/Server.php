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

    private readonly CorsHeaders $corsHeaders;

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
        private readonly RedisGateway $redisGateway,
        private readonly Budget $budget,
        private readonly \Closure $clock,
    ) {
        $this->corsHeaders = new CorsHeaders($settings);
        $this->path = rtrim((string) parse_url($settings->get('base_url'), PHP_URL_PATH), '/') . '/brain/stream';
        $this->prefix = $settings->get('redis.prefix');
    }

    public function handle(ServerRequestInterface $serverRequest, ?\Closure $disconnect = null): mixed
    {
        $respond = fn (int $status, mixed $body = ''): \Psr\Http\Message\ResponseInterface => $this->corsHeaders->apply($serverRequest, new Response($status, [
            'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer',
            'Content-Type' => $status === 200 ? 'text/event-stream' : 'text/plain; charset=utf-8',
            'Connection' => 'close', 'X-Accel-Buffering' => 'no',
        ], $body));
        if ($serverRequest->getUri()->getPath() !== $this->path) {
            return $respond(404);
        }

        if ($serverRequest->getMethod() === 'OPTIONS') {
            return $respond(204);
        }

        if ($serverRequest->getMethod() !== 'GET') {
            return $respond(405)->withHeader('Allow', 'GET, OPTIONS');
        }

        if ($serverRequest->hasHeader('Transfer-Encoding')
            || ! in_array($serverRequest->getHeaderLine('Content-Length'), ['', '0'], true)) {
            return $respond(400);
        }

        $query = $serverRequest->getUri()->getQuery();
        if (strlen($query) > 32768 || count($serverRequest->getHeader('X-Claire-Auth')) > 1) {
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

        $header = $serverRequest->getHeaderLine('X-Claire-Auth');
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
            'path' => $serverRequest->getUri()->getPath(), 'method' => 'GET',
            'threadId' => $parameters['threadId'], 'sessionId' => $parameters['sessionId'],
        ];
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 16384) {
            return $respond(400);
        }

        if ($this->stopping || ! $this->redisGateway->ready()
            || count($this->connections) + count($this->admissions) >= $this->config->get('max_connections')
            || count($this->admissions) >= $this->config->get('max_http_requests')) {
            return $respond(503)->withHeader('Retry-After', '1');
        }

        ++$this->sequence;
        $id = (string) $this->sequence;
        $promise = $this->backend->request('open', $input);
        $this->admissions[$id] = $promise;
        return new Promise(function ($resolve) use ($promise, $id, $parameters, $respond, $disconnect): void {
            $promise->then(function (array $authorization) use ($id, $parameters, $respond, $resolve, $disconnect): void {
                $admitted = isset($this->admissions[$id]);
                unset($this->admissions[$id]);
                $opaque = $authorization['authorization'] ?? null;
                if (! $admitted || $this->stopping || ! $this->redisGateway->ready()
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
                    $this->redisGateway,
                    $this->budget,
                    $this->clock,
                    function (string $closedId, string $reason) use ($disconnect, &$streaming): void {
                        unset($this->connections[$closedId]);
                        $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
                        if ($streaming && $disconnect instanceof \Closure) {
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
            }, function (\Throwable $throwable) use ($id, $respond, $resolve): void {
                unset($this->admissions[$id]);
                $this->reasons['backend_open_failed'] = ($this->reasons['backend_open_failed'] ?? 0) + 1;
                $resolve($respond(in_array($throwable->getCode(), [400, 401, 403], true) ? $throwable->getCode() : 503));
            });
        }, function () use ($promise, $id): never {
            unset($this->admissions[$id]);
            ($this->connections[$id] ?? null)?->close('handshake_cancelled');
            $promise->cancel();
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
        foreach ($this->admissions as $admission) {
            $admission->cancel();
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
        return $this->validAuthorizationContext($data, $parameters)
            && $this->validAuthorizationPeriod($data)
            && $this->validRememberAuthorization($data);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $parameters
     */
    private function validAuthorizationContext(array $data, array $parameters): bool
    {
        return array_all(
            ['authorization', 'userId', 'threadId', 'sessionId'],
            static fn (string $key): bool => is_string($data[$key] ?? null)
                && $data[$key] !== '' && strlen($data[$key]) <= 4096
        )
            && preg_match('/^[a-f0-9]{64}$/D', $data['authorization']) === 1
            && array_all(
                ['threadId', 'sessionId'],
                static fn (string $key): bool => $data[$key] === $parameters[$key]
            );
    }

    /** @param array<string, mixed> $data */
    private function validAuthorizationPeriod(array $data): bool
    {
        if (! array_all(
            ['openedAt', 'deadline'],
            static fn (string $key): bool => (is_int($data[$key] ?? null) || is_float($data[$key] ?? null))
                && is_finite((float) $data[$key])
        )) {
            return false;
        }

        return ! ($data['openedAt'] > ($this->clock)() || $data['deadline'] <= ($this->clock)()
            || $data['deadline'] <= $data['openedAt']
            || $data['deadline'] - $data['openedAt'] > $this->config->get('max_duration'));
    }

    /** @param array<string, mixed> $data */
    private function validRememberAuthorization(array $data): bool
    {
        if (! array_key_exists('remember', $data)) {
            return false;
        }

        $remember = $data['remember'];
        return $remember === null || (is_array($remember) && is_string($remember['id'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $remember['id']) === 1
            && is_int($remember['expires'] ?? null) && $remember['expires'] > ($this->clock)());
    }
}
