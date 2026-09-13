<?php

declare(strict_types=1);

namespace App\Sse;

use App\Services\ChatGenerationState;
use App\Services\RememberSession;
use App\Services\Settings;
use Clue\React\Redis\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use function React\Promise\all;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\reject;

final class RedisHub implements RedisGateway
{
    private mixed $subscriber = null;
    private mixed $commands = null;
    /**
     * @var array<string, array{identity: \stdClass, listeners: array<int|string, callable>,
     *     ack: PromiseInterface|null, unsubscribing: bool}>
     */
    private array $channels = [];
    /** @var array<string, PromiseInterface> */
    private array $reads = [];
    /** @var array<string, array{string, string, Deferred}> */
    private array $waiting = [];
    private int $activeReads = 0;
    private int $epoch = 0;
    private int $failures = 0;
    private bool $stopped = false;
    private ?TimerInterface $retry = null;
    private ?PromiseInterface $connecting = null;
    private readonly string $uri;
    private readonly string $prefix;
    private int $messages = 0;
    private int $messageBytes = 0;
    private float $dispatchSeconds = 0.0;
    private int $listenerErrors = 0;

    public function __construct(
        Settings $settings,
        private readonly Config $config,
        private readonly LoopInterface $loop,
        private readonly \Closure $unavailable,
        private readonly ?\Closure $connect = null,
        private readonly ?\Closure $jitter = null,
    ) {
        $redis = $settings->get('redis');
        $this->prefix = $redis['prefix'];
        $host = $redis['host'];
        if (! is_string($host) || ! preg_match('/^[a-zA-Z0-9.:-]+$/D', $host)) {
            throw new \InvalidArgumentException('Invalid Redis host');
        }
        if (str_contains($host, ':')) {
            $host = '[' . $host . ']';
        }
        $password = $redis['password'] ?? null;
        $this->uri = 'redis://' . ($password === null || $password === '' ? '' : ':' . rawurlencode($password) . '@')
            . $host . ':' . $redis['port'] . '/' . $redis['database']
            . '?timeout=' . max(0.1, (float) $redis['timeout']);
    }

    public function start(): void
    {
        if ($this->stopped || $this->connecting !== null || $this->ready()) {
            return;
        }
        ++$this->epoch;
        $epoch = $this->epoch;
        $factory = new Factory($this->loop);
        $clients = [];
        $requests = [];
        foreach ([0, 1] as $index) {
            $request = $this->connect !== null ? ($this->connect)($this->uri)
                : ($index === 0 ? PubSubClient::connect($this->uri, $this->loop) : $factory->createClient($this->uri));
            $requests[] = $request->then(function ($client) use (&$clients, $index, $epoch) {
                if ($this->stopped || $epoch !== $this->epoch) {
                    $client->close();
                    throw new \RuntimeException('Stale Redis connection');
                }
                $clients[$index] = $client;
                $client->on('error', function () use ($epoch): void {
                    $this->lost($epoch);
                });
                $client->on('close', function () use ($epoch): void {
                    $this->lost($epoch);
                });
                return $client;
            });
        }
        $this->connecting = Async::timeout(all($requests), $this->loop, $this->config->get('http_timeout'));
        $this->connecting->then(function (array $connected) use ($epoch): void {
            $this->connecting = null;
            if ($epoch !== $this->epoch || $this->stopped) {
                foreach ($connected as $client) {
                    $client->close();
                }
                return;
            }
            [$this->subscriber, $this->commands] = $connected;
            $this->failures = 0;
            $this->subscriber->on('message', function (string $channel, string $message): void {
                $started = hrtime(true);
                ++$this->messages;
                $this->messageBytes += strlen($message);
                foreach ($this->channels[$channel]['listeners'] ?? [] as $listener) {
                    try {
                        $listener($message);
                    } catch (\Throwable) {
                        ++$this->listenerErrors;
                    }
                }
                $this->dispatchSeconds += (hrtime(true) - $started) / 1_000_000_000;
            });
        }, function () use ($epoch, &$clients): void {
            $this->lost($epoch);
            foreach ($clients as $client) {
                $client->close();
            }
        });
    }

    public function ready(): bool
    {
        return ! $this->stopped && $this->subscriber !== null && $this->commands !== null;
    }

    /** @param callable(string): void $listener */
    public function subscribe(string $channel, string $id, callable $listener): PromiseInterface
    {
        if (! $this->ready()) {
            return reject(new \RuntimeException('Redis unavailable'));
        }
        if (! isset($this->channels[$channel])) {
            $this->channels[$channel] = [
                'identity' => new \stdClass(), 'listeners' => [], 'ack' => null, 'unsubscribing' => false,
            ];
            $this->channels[$channel]['ack'] = $this->subscription('subscribe', $channel);
        } elseif ($this->channels[$channel]['unsubscribing']) {
            $this->channels[$channel]['unsubscribing'] = false;
            $this->channels[$channel]['ack'] = $this->channels[$channel]['ack']->then(
                fn () => $this->subscription('subscribe', $channel),
            );
        }
        $this->channels[$channel]['listeners'][$id] = $listener;
        return $this->channels[$channel]['ack'];
    }

    public function unsubscribe(string $channel, string $id): void
    {
        if (! isset($this->channels[$channel]['listeners'][$id])) {
            return;
        }
        unset($this->channels[$channel]['listeners'][$id]);
        if ($this->channels[$channel]['listeners'] !== []) {
            return;
        }
        $this->channels[$channel]['unsubscribing'] = true;
        $epoch = $this->epoch;
        $identity = $this->channels[$channel]['identity'];
        $operation = $this->channels[$channel]['ack'] = $this->channels[$channel]['ack']->then(
            fn () => $this->subscription('unsubscribe', $channel),
        );
        $operation->then(function () use ($channel, $epoch, $identity, $operation): void {
            // A later close can set unsubscribing again while this older ACK is still pending.
            if ($epoch === $this->epoch && ($this->channels[$channel]['identity'] ?? null) === $identity
                && $this->channels[$channel]['ack'] === $operation
                && $this->channels[$channel]['unsubscribing'] && $this->channels[$channel]['listeners'] === []) {
                unset($this->channels[$channel]);
            }
        }, static function (): void {
        });
    }

    /** @return PromiseInterface<array<string, string>> */
    public function generation(string $userId, string $threadId): PromiseInterface
    {
        return $this->read('hgetall', ChatGenerationState::stateKey($this->prefix, $userId, $threadId))->then(
            static function (array $values): array {
                $state = [];
                $count = count($values);
                for ($index = 0; $index < $count; $index += 2) {
                    $state[$values[$index]] = $values[$index + 1];
                }
                return $state;
            },
        );
    }

    /** @return PromiseInterface<array<string, mixed>|null> */
    public function remember(string $id): PromiseInterface
    {
        return $this->read('get', RememberSession::rememberKey($this->prefix, $id))->then(static function ($value): ?array {
            try {
                $data = is_string($value) ? json_decode($value, true, 16, JSON_THROW_ON_ERROR) : null;
                return is_array($data) ? $data : null;
            } catch (\JsonException) {
                return null;
            }
        });
    }

    public function stop(): void
    {
        $this->stopped = true;
        if ($this->retry !== null) {
            $this->loop->cancelTimer($this->retry);
            $this->retry = null;
        }
        $this->lost($this->epoch);
    }

    public function subscriptionCount(): int
    {
        return count($this->channels);
    }

    /** @return array<string, bool|int|float> */
    public function metrics(): array
    {
        return ['redis_ready' => $this->ready(), 'subscriptions' => count($this->channels),
            'redis_commands' => $this->activeReads, 'redis_commands_waiting' => count($this->waiting),
            'publications_received' => $this->messages,
            'publication_bytes' => $this->messageBytes, 'publication_dispatch_seconds_total' => $this->dispatchSeconds,
            'publication_listener_errors' => $this->listenerErrors,
        ];
    }

    private function subscription(string $operation, string $channel): PromiseInterface
    {
        if (! $this->ready()) {
            return reject(new \RuntimeException('Redis unavailable'));
        }
        $epoch = $this->epoch;
        $promise = Async::timeout(
            $this->subscriber->$operation($channel),
            $this->loop,
            $this->config->get('http_timeout')
        );
        $promise->then(null, function () use ($epoch): void {
            $this->lost($epoch);
        });
        return $promise;
    }

    private function read(string $operation, string $key): PromiseInterface
    {
        if (! $this->ready()) {
            return reject(new \RuntimeException('Redis unavailable'));
        }
        $id = $operation . ':' . $key;
        if (isset($this->reads[$id])) {
            return $this->reads[$id];
        }
        if (count($this->reads) >= 2 * $this->config->get('max_connections')) {
            return reject(new \RuntimeException('Redis command admission exhausted'));
        }
        $epoch = $this->epoch;
        $deferred = new Deferred();
        $promise = $this->reads[$id] = Async::timeout(
            $deferred->promise(),
            $this->loop,
            $this->config->get('http_timeout')
        );
        $this->waiting[$id] = [$operation, $key, $deferred];
        $this->reads[$id]->then(function () use ($id, $epoch): void {
            if ($epoch === $this->epoch) {
                unset($this->reads[$id]);
            }
        }, function () use ($epoch): void {
            $this->lost($epoch);
        });
        $this->pumpReads();
        return $promise;
    }

    private function pumpReads(): void
    {
        while ($this->ready() && $this->waiting !== [] && $this->activeReads < $this->config->get('max_redis_commands')) {
            $id = array_key_first($this->waiting);
            [$operation, $key, $deferred] = $this->waiting[$id];
            unset($this->waiting[$id]);
            ++$this->activeReads;
            $epoch = $this->epoch;
            $this->commands->$operation($key)->then(function ($value) use ($deferred, $epoch): void {
                if ($epoch !== $this->epoch) {
                    return;
                }
                --$this->activeReads;
                $deferred->resolve($value);
                $this->pumpReads();
            }, static function (\Throwable $error) use ($deferred): void {
                $deferred->reject($error);
            });
        }
    }

    private function lost(int $epoch): void
    {
        if ($epoch !== $this->epoch) {
            return;
        }
        ++$this->epoch;
        $subscriber = $this->subscriber;
        $commands = $this->commands;
        $connecting = $this->connecting;
        $this->subscriber = $this->commands = $this->connecting = null;
        $reads = $this->reads;
        $this->channels = $this->reads = $this->waiting = [];
        $this->activeReads = 0;
        ($this->unavailable)();
        $subscriber?->close();
        $commands?->close();
        $connecting?->cancel();
        foreach ($reads as $read) {
            $read->cancel();
        }
        if (! $this->stopped) {
            $jitter = $this->jitter !== null ? ($this->jitter)() : random_int(750, 1250) / 1000;
            $delay = min(30, 0.25 * (2 ** min(7, $this->failures))) * $jitter;
            ++$this->failures;
            $this->retry = $this->loop->addTimer($delay, function (): void {
                $this->retry = null;
                $this->start();
            });
        }
    }
}
