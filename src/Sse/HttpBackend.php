<?php

declare(strict_types=1);

namespace App\Sse;

use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use function React\Promise\reject;
use React\Stream\ReadableStreamInterface;

final class HttpBackend implements Backend
{
    private int $active = 0;
    /** @var array<string, true> */
    private array $closing = [];
    private readonly Browser $browser;
    private int $errors = 0;
    private int $completed = 0;
    private float $totalSeconds = 0.0;
    private float $snapshotSeconds = 0.0;
    private int $snapshots = 0;
    private bool $stopped = false;
    private int $sequence = 0;
    /** @var array<int, PromiseInterface> */
    private array $pending = [];

    public function __construct(
        private readonly Config $config,
        private readonly LoopInterface $loop,
        private readonly Budget $budget,
        private readonly ?\Closure $send = null,
    ) {
        $this->browser = (new Browser($loop))->withFollowRedirects(false)->withRejectErrorResponse(false)
            ->withTimeout($config->get('http_timeout'));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function request(string $operation, array $payload): PromiseInterface
    {
        if ($this->stopped || ! in_array($operation, ['open', 'snapshot', 'close'], true)
            || $this->active >= $this->config->get('max_http_requests')) {
            return reject(new \RuntimeException('SSE backend admission exhausted'));
        }
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 16384) {
            return reject(new \RuntimeException('SSE backend request body limit'));
        }
        ++$this->active;
        $started = microtime(true);
        $headers = [
            'X-Claire-Sse-Secret' => $this->config->get('secret'),
            'Content-Type' => 'application/json', 'Accept' => 'application/json',
        ];
        $url = $this->config->get('backend') . '/' . $operation;
        $request = $this->send !== null ? ($this->send)('POST', $url, $headers, $encoded)
            : $this->browser->requestStreaming('POST', $url, $headers, $encoded);
        $stream = null;
        $bytes = 0;
        $body = '';
        $pending = new Promise(function ($resolve, $reject) use ($request, $operation, &$stream, &$bytes, &$body): void {
            $request->then(function (ResponseInterface $response) use ($resolve, $reject, $operation, &$stream, &$bytes, &$body): void {
                $stream = $response->getBody();
                if ($operation === 'close' && $response->getStatusCode() === 204) {
                    $stream->close();
                    $resolve([]);
                    return;
                }
                if (! $stream instanceof ReadableStreamInterface) {
                    $reject(new \RuntimeException('Backend did not return a stream'));
                    return;
                }
                if ($response->getStatusCode() !== 200) {
                    $stream->close();
                    $reject(new \RuntimeException('SSE backend rejected operation', $response->getStatusCode()));
                    return;
                }
                $stream->on('data', function (string $chunk) use ($reject, &$stream, &$bytes, &$body): void {
                    $size = strlen($chunk);
                    if ($bytes + $size > $this->config->get('max_client_buffer') || ! $this->budget->acquire($size)) {
                        $reject(new \RuntimeException('SSE backend body limit'));
                        $stream->close();
                        return;
                    }
                    $bytes += $size;
                    $body .= $chunk;
                });
                $stream->on('error', $reject);
                $stream->on('end', static function () use ($resolve, $reject, &$body): void {
                    try {
                        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                        if (! is_array($data)) {
                            throw new \RuntimeException('Invalid SSE backend response');
                        }
                        $resolve($data);
                    } catch (\Throwable $error) {
                        $reject($error);
                    }
                });
                $stream->on('close', static function () use ($reject): void {
                    $reject(new \RuntimeException('SSE backend body closed'));
                });
            }, $reject);
        }, static function () use ($request, &$stream): void {
            $request->cancel();
            $stream?->close();
            throw new \RuntimeException('SSE backend cancelled');
        });
        ++$this->sequence;
        $id = $this->sequence;
        $this->pending[$id] = $pending;
        $result = Async::timeout($pending, $this->loop, $this->config->get('http_timeout'))->finally(
            function () use (&$bytes, &$body, &$stream, $started, $operation, $id): void {
                unset($this->pending[$id]);
                $stream?->close();
                $this->budget->release($bytes);
                $bytes = 0;
                $body = '';
                --$this->active;
                ++$this->completed;
                $elapsed = microtime(true) - $started;
                $this->totalSeconds += $elapsed;
                if ($operation === 'snapshot') {
                    ++$this->snapshots;
                    $this->snapshotSeconds += $elapsed;
                }
                $this->drainClosures();
            },
        );
        $result->then(null, function (): void {
            ++$this->errors;
        });
        return $result;
    }

    /** @return array<string, int|float> */
    public function metrics(): array
    {
        return ['http_active' => $this->active, 'http_completed' => $this->completed, 'http_errors' => $this->errors,
            'http_seconds_total' => $this->totalSeconds, 'snapshot_count' => $this->snapshots,
            'snapshot_seconds_total' => $this->snapshotSeconds, 'authorization_cleanup_pending' => count($this->closing),
        ];
    }

    public function close(string $authorization): void
    {
        if ($this->stopped) {
            return;
        }
        // Bounded best-effort cleanup; Redis authorization TTL remains the crash/saturation fallback.
        if (count($this->closing) < $this->config->get('max_connections')) {
            $this->closing[$authorization] = true;
        }
        $this->drainClosures();
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->closing = [];
        foreach ($this->pending as $pending) {
            $pending->cancel();
        }
        $this->pending = [];
    }

    private function drainClosures(): void
    {
        while ($this->closing !== [] && $this->active < $this->config->get('max_http_requests')) {
            $authorization = array_key_first($this->closing);
            unset($this->closing[$authorization]);
            $this->request('close', ['authorization' => $authorization])->then(null, static function (): void {
            });
        }
    }
}
