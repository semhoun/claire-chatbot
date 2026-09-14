<?php

declare(strict_types=1);

namespace App\Sse;

use App\Services\Settings;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

final readonly class Daemon
{
    public function __construct(private Settings $settings)
    {
    }

    public function run(): void
    {
        $settings = $this->settings;
        $config = new Config($settings);
        $loop = Loop::get();
        $budget = new Budget($config->get('max_global_buffer'));
        $backend = new HttpBackend($config, $loop, $budget);
        $application = null;
        $redis = new RedisHub($settings, $config, $loop, static function () use (&$application): void {
            $application?->unavailable();
        });
        $application = new Server($settings, $config, $loop, $backend, $redis, $budget, static fn (): float => microtime(true));
        $socket = new SocketServer($config->get('listen'), [], $loop);
        $sockets = [];
        $remoteSockets = [];
        // Bound even unauthenticated sockets and incomplete headers, before HTTP parsing/admission.
        $socket->on('connection', static function (ConnectionInterface $connection) use (&$sockets, &$remoteSockets, $config, $loop, $budget): void {
            // React sockets can retain up to 64 KiB before write=false; reserve this independently of SSE frames.
            if (count($sockets) >= $config->get('max_connections') + $config->get('max_http_requests')
                || ! $budget->acquire(65536)) {
                $connection->close();
                return;
            }
            $id = spl_object_id($connection);
            $sockets[$id] = $connection;
            $remote = parse_url((string) $connection->getRemoteAddress());
            $key = ($remote['host'] ?? '') . ':' . ($remote['port'] ?? '');
            $remoteSockets[$key] = $connection;
            $timer = $loop->addTimer($config->get('http_timeout'), $connection->close(...));
            $headers = '';
            $headerBytes = 0;
            $listener = static function (string $chunk) use (&$headers, &$headerBytes, &$listener, $connection, $loop, $timer, $budget): void {
                if (strlen($headers) + strlen($chunk) > 65536 || ! $budget->acquire(strlen($chunk))) {
                    $connection->close();
                    return;
                }
                $headerBytes += strlen($chunk);
                $headers .= $chunk;
                if (str_contains($headers, "\r\n\r\n")) {
                    $loop->cancelTimer($timer);
                    $connection->removeListener('data', $listener);
                    $headers = '';
                    $budget->release($headerBytes);
                    $headerBytes = 0;
                }
            };
            $connection->on('data', $listener);
            $connection->on('close', static function () use (&$sockets, &$remoteSockets, &$headerBytes, $key, $id, $loop, $timer, $budget): void {
                unset($sockets[$id], $remoteSockets[$key]);
                $loop->cancelTimer($timer);
                $budget->release(65536 + $headerBytes);
                $headerBytes = 0;
            });
        });
        $http = new HttpServer(
            $loop,
            new StreamingRequestMiddleware(),
            static function (ServerRequestInterface $request) use ($application, &$remoteSockets): mixed {
                $params = $request->getServerParams();
                $connection = $remoteSockets[($params['REMOTE_ADDR'] ?? '') . ':' . ($params['REMOTE_PORT'] ?? '')] ?? null;
                return $application->handle($request, $connection !== null ? $connection->close(...) : null);
            }
        );
        // Never log request URLs or credentials.
        $http->on('error', static function (): void {
        });
        $http->listen($socket);
        $redis->start();
        $last = microtime(true);
        stream_set_blocking(STDOUT, false);
        $metrics = $loop->addPeriodicTimer(15, static function () use ($application, $redis, $backend, &$last): void {
            $now = microtime(true);
            fwrite(STDOUT, json_encode([
                ...$application->metrics(), ...$redis->metrics(), ...$backend->metrics(),
                'loop_lag_seconds' => max(0, $now - $last - 15),
            ], JSON_THROW_ON_ERROR) . "\n");
            $last = $now;
        });
        $stopping = false;
        $shutdown = static function () use (&$stopping, &$sockets, $socket, $application, $redis, $backend, $loop, $config, $metrics): void {
            if ($stopping) {
                return;
            }
            $stopping = true;
            $socket->close();
            $loop->cancelTimer($metrics);
            $application->stop();
            $redis->stop();
            $loop->addTimer($config->get('shutdown_timeout'), static function () use (&$sockets, $backend, $loop): void {
                $backend->stop();
                foreach ($sockets as $connection) {
                    $connection->close();
                }
                $loop->stop();
            });
        };
        $loop->addSignal(SIGTERM, $shutdown);
        $loop->addSignal(SIGINT, $shutdown);
        $loop->run();
    }
}
