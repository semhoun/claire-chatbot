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
        $httpBackend = new HttpBackend($config, $loop, $budget);
        $application = null;
        $redisHub = new RedisHub($settings, $config, $loop, static function () use (&$application): void {
            $application?->unavailable();
        });
        $application = new Server($settings, $config, $loop, $httpBackend, $redisHub, $budget, static fn (): float => microtime(true));
        $socketServer = new SocketServer($config->get('listen'), [], $loop);
        $sockets = [];
        $remoteSockets = [];
        // Bound even unauthenticated sockets and incomplete headers, before HTTP parsing/admission.
        $socketServer->on('connection', static function (ConnectionInterface $connection) use (&$sockets, &$remoteSockets, $config, $loop, $budget): void {
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
        $httpServer = new HttpServer(
            $loop,
            new StreamingRequestMiddleware(),
            static function (ServerRequestInterface $serverRequest) use ($application, &$remoteSockets): mixed {
                $params = $serverRequest->getServerParams();
                $connection = $remoteSockets[($params['REMOTE_ADDR'] ?? '') . ':' . ($params['REMOTE_PORT'] ?? '')] ?? null;
                return $application->handle($serverRequest, $connection instanceof \React\Socket\ConnectionInterface ? $connection->close(...) : null);
            }
        );
        // Never log request URLs or credentials.
        $httpServer->on('error', static function (): void {
        });
        $httpServer->listen($socketServer);

        $redisHub->start();
        $last = microtime(true);
        stream_set_blocking(STDOUT, false);
        $metrics = $loop->addPeriodicTimer(15, static function () use ($application, $redisHub, $httpBackend, &$last): void {
            $now = microtime(true);
            fwrite(STDOUT, json_encode([
                ...$application->metrics(), ...$redisHub->metrics(), ...$httpBackend->metrics(),
                'loop_lag_seconds' => max(0, $now - $last - 15),
            ], JSON_THROW_ON_ERROR) . "\n");
            $last = $now;
        });
        $stopping = false;
        $shutdown = static function () use (&$stopping, &$sockets, $socketServer, $application, $redisHub, $httpBackend, $loop, $config, $metrics): void {
            if ($stopping) {
                return;
            }

            $stopping = true;
            $socketServer->close();
            $loop->cancelTimer($metrics);
            $application->stop();
            $redisHub->stop();
            $loop->addTimer($config->get('shutdown_timeout'), static function () use (&$sockets, $httpBackend, $loop): void {
                $httpBackend->stop();
                foreach ($sockets as $socket) {
                    $socket->close();
                }

                $loop->stop();
            });
        };
        $loop->addSignal(SIGTERM, $shutdown);
        $loop->addSignal(SIGINT, $shutdown);
        $loop->run();
    }
}
