<?php

declare(strict_types=1);

namespace App\Sse;

use App\Services\ChatGenerationState;
use App\Services\RememberSession;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

final class Connection
{
    private readonly Output $output;
    private bool $closed = false;
    private bool $initialized = false;
    private bool $busy = false;
    private bool $snapshotWanted = true;
    private bool $checking = false;
    private bool $checkingRemember = false;
    private bool $scheduled = false;
    private bool $snapshotRunning = false;
    /** @var list<string> */
    private array $queue = [];
    private int $bytes = 0;
    /** @var array{string, string}|array{} */
    private array $generation = [];
    /** @var list<TimerInterface> */
    private array $timers = [];
    private ?TimerInterface $writeTimer = null;
    private ?PromiseInterface $http = null;
    /** @var array<string, mixed>|null */
    private ?array $pendingPayload = null;
    /** @var array<string, mixed>|null */
    private ?array $snapshotData = null;
    /** @var array<string, mixed> */
    private array $effects = [];
    private int $snapshotBytes = 0;
    private int $effectBytes = 0;
    private readonly Deferred $opened;
    private readonly int $clientLimit;

    /**
     * @param array{authorization: string, userId: string, threadId: string, sessionId: string,
     *     openedAt: int|float, deadline: int|float, remember: array{id: string, expires: int}|null} $authorization
     */
    public function __construct(
        public readonly string $id,
        public readonly string $channel,
        private readonly array $authorization,
        private readonly Config $config,
        private readonly LoopInterface $loop,
        private readonly Backend $backend,
        private readonly RedisGateway $redis,
        private readonly Budget $budget,
        private readonly \Closure $clock,
        private readonly \Closure $onClose,
        int $transportBytes = 0,
    ) {
        $this->opened = new Deferred();
        $this->clientLimit = max(0, $config->get('max_client_buffer') - $transportBytes);
        $this->output = new Output($budget, $this->clientLimit);
        $this->output->on('close', fn () => $this->close('client_closed'));
        $this->output->on('drain', function (): void {
            if ($this->writeTimer !== null) {
                $this->loop->cancelTimer($this->writeTimer);
                $this->writeTimer = null;
            }
            $this->schedule();
        });
    }

    public function output(): Output
    {
        return $this->output;
    }

    public function start(): PromiseInterface
    {
        $remaining = $this->authorization['deadline'] - ($this->clock)();
        if ($remaining <= 0) {
            $this->close('deadline');
            return $this->opened->promise();
        }
        $this->timers[] = $this->loop->addTimer($remaining, fn () => $this->close('deadline'));
        $this->timers[] = $this->loop->addPeriodicTimer($this->config->get('keepalive'), function (): void {
            if ($this->initialized && ! $this->output->blocked()) {
                $this->send(": keepalive\n\n");
            }
        });
        $this->timers[] = $this->loop->addPeriodicTimer($this->config->get('check_interval'), $this->checkGeneration(...));
        if ($this->authorization['remember'] !== null) {
            $this->timers[] = $this->loop->addPeriodicTimer($this->config->get('check_interval'), $this->checkRemember(...));
            $expires = $this->authorization['remember']['expires'] - ($this->clock)();
            $this->timers[] = $this->loop->addTimer(max(0, $expires), fn () => $this->close('remember_expired'));
        }
        $this->redis->subscribe($this->channel, $this->id, $this->receive(...))->then(function (): void {
            if ($this->valid()) {
                $this->snapshot();
            }
        }, fn () => $this->close('subscribe_failed'));
        return $this->opened->promise();
    }

    public function receive(string $raw): void
    {
        if (! $this->valid()) {
            return;
        }
        $size = strlen($raw);
        if (count($this->queue) >= $this->config->get('max_pending_events')
            || $size + $this->bytes + $this->output->bufferedBytes() > $this->clientLimit
            || ! $this->budget->acquire($size)) {
            $this->close('event_buffer_limit');
            return;
        }
        $this->bytes += $size;
        $this->queue[] = $raw;
        $this->schedule();
    }

    public function requestSnapshot(): void
    {
        if ($this->closed) {
            return;
        }
        // A running snapshot already includes a post-HTTP authoritative generation check.
        if (! $this->snapshotRunning) {
            $this->snapshotWanted = true;
        }
        $this->schedule();
    }

    public function close(string $reason): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->timers as $timer) {
            $this->loop->cancelTimer($timer);
        }
        $this->timers = [];
        if ($this->writeTimer !== null) {
            $this->loop->cancelTimer($this->writeTimer);
        }
        $this->writeTimer = null;
        $http = $this->http;
        $this->http = null;
        $http?->cancel();
        $this->budget->release($this->bytes);
        $this->bytes = 0;
        $this->queue = [];
        $this->pendingPayload = $this->snapshotData = null;
        $this->effects = [];
        $this->redis->unsubscribe($this->channel, $this->id);
        $this->output->close();
        $this->backend->close($this->authorization['authorization']);
        $this->opened->reject(new \RuntimeException('SSE connection closed'));
        ($this->onClose)($this->id, $reason);
    }

    private function valid(): bool
    {
        if ($this->closed) {
            return false;
        }
        if (($this->clock)() >= $this->authorization['deadline']) {
            $this->close('deadline');
            return false;
        }
        return true;
    }

    private function schedule(): void
    {
        if ($this->scheduled || $this->closed) {
            return;
        }
        $this->scheduled = true;
        $this->loop->futureTick(function (): void {
            $this->scheduled = false;
            if (! $this->valid() || ! $this->initialized || $this->busy || $this->output->blocked()) {
                return;
            }
            if ($this->snapshotWanted) {
                $this->snapshot();
                return;
            }
            $raw = array_shift($this->queue);
            if ($raw === null) {
                return;
            }
            $this->budget->release(strlen($raw));
            $this->bytes -= strlen($raw);
            try {
                $event = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $this->schedule();
                return;
            }
            $payload = $event['payload'] ?? null;
            $name = $event['event'] ?? null;
            if (! is_array($payload) || ($event['version'] ?? null) !== 1
                || ($payload['sessionId'] ?? null) !== $this->authorization['sessionId']
                || ($payload['threadId'] ?? null) !== $this->authorization['threadId']
                || ($event['threadId'] ?? null) !== $this->authorization['threadId']
                || ! in_array($name, ['chat.snapshot', 'chat.assistant.start', 'chat.assistant.placeholder',
                    'chat.assistant.update', 'chat.tool.update', 'chat.assistant.done', 'chat.audio.ready',
                    'chat.audio.error', 'chat.error',
                ], true)) {
                $this->schedule();
                return;
            }
            if ($name === 'chat.snapshot') {
                // Adjacent invalidations need one authoritative read, not one SQL call per publication.
                while ($this->queue !== []) {
                    try {
                        $next = json_decode($this->queue[0], true, 64, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        break;
                    }
                    if (($next['event'] ?? null) !== 'chat.snapshot' || ($next['version'] ?? null) !== 1
                        || ($next['threadId'] ?? null) !== $this->authorization['threadId']
                        || ($next['payload']['threadId'] ?? null) !== $this->authorization['threadId']
                        || ($next['payload']['sessionId'] ?? null) !== $this->authorization['sessionId']) {
                        break;
                    }
                    $removed = array_shift($this->queue);
                    $this->budget->release(strlen($removed));
                    $this->bytes -= strlen($removed);
                    $payload = $next['payload'];
                }
                $effects = array_intersect_key(
                    $payload,
                    array_flip(['restoredMessage', 'generationMessageId', 'generationStatus'])
                );
                $this->snapshot(0, $effects);
                return;
            }
            if (! is_string($payload['messageId'] ?? null) || $payload['messageId'] === '') {
                $this->schedule();
                return;
            }
            if (str_starts_with($name, 'chat.audio.')) {
                $this->event($name, $payload);
                $this->schedule();
                return;
            }
            $this->busy = true;
            // Keep the in-flight payload accounted for until its shared read settles.
            $size = strlen($raw);
            if (! $this->budget->acquire($size)) {
                $this->close('global_buffer_limit');
                return;
            }
            $this->bytes += $size;
            $this->pendingPayload = $payload;
            $this->redis->generation($this->authorization['userId'], $this->authorization['threadId'])->then(
                function (array $state) use ($name, $size): void {
                    if (! $this->valid()) {
                        return;
                    }
                    $payload = $this->pendingPayload;
                    $this->pendingPayload = null;
                    $this->budget->release($size);
                    $this->bytes -= $size;
                    $this->busy = false;
                    if (ChatGenerationState::acceptsState($state, $name, $payload['messageId'])) {
                        $this->event($name, $payload);
                        if (in_array($name, ['chat.assistant.done', 'chat.error'], true)) {
                            $this->snapshotWanted = true;
                        }
                    }
                    $this->schedule();
                },
                fn () => $this->close('generation_unavailable'),
            );
        });
    }

    /** @param array<string, mixed> $effects */
    private function snapshot(int $attempt = 0, array $effects = []): void
    {
        if (! $this->valid()) {
            return;
        }
        $this->busy = true;
        $this->snapshotRunning = true;
        $this->snapshotWanted = false;
        $effectBytes = strlen(json_encode($effects, JSON_THROW_ON_ERROR));
        if ($this->bytes + $effectBytes > $this->clientLimit
            || ! $this->budget->acquire($effectBytes)) {
            $this->close('snapshot_buffer_limit');
            return;
        }
        $this->effects = $effects;
        $this->effectBytes = $effectBytes;
        $this->bytes += $effectBytes;
        $this->http = $this->backend->request('snapshot', ['authorization' => $this->authorization['authorization']]);
        $this->http->then(function (array $snapshot) use ($attempt): void {
            $this->http = null;
            if (! $this->valid()) {
                return;
            }
            if (! is_array($snapshot['generation'] ?? null) || ! is_array($snapshot['messages'] ?? null)
                || ! is_bool($snapshot['responding'] ?? null) || ! is_array($snapshot['audioRequestIds'] ?? null)
                || ($snapshot['threadId'] ?? null) !== $this->authorization['threadId']
                || ($snapshot['sessionId'] ?? null) !== $this->authorization['sessionId']) {
                $this->close('invalid_snapshot');
                return;
            }
            $size = strlen(json_encode($snapshot, JSON_THROW_ON_ERROR));
            if ($this->bytes + $size > $this->clientLimit || ! $this->budget->acquire($size)) {
                $this->close('snapshot_buffer_limit');
                return;
            }
            $this->snapshotData = $snapshot;
            $this->snapshotBytes = $size;
            $this->bytes += $size;
            $this->redis->generation($this->authorization['userId'], $this->authorization['threadId'])->then(
                function (array $state) use ($attempt): void {
                    if (! $this->valid()) {
                        return;
                    }
                    $snapshot = $this->snapshotData;
                    $effects = $this->effects;
                    $this->snapshotData = null;
                    $this->effects = [];
                    $this->budget->release($this->snapshotBytes + $this->effectBytes);
                    $this->bytes -= $this->snapshotBytes + $this->effectBytes;
                    $this->snapshotBytes = $this->effectBytes = 0;
                    if (self::signature($state) !== self::signature($snapshot['generation'])) {
                        if ($attempt >= 2) {
                            $this->close('snapshot_unstable');
                            return;
                        }
                        $this->snapshot($attempt + 1);
                        return;
                    }
                    $this->generation = self::signature($state);
                    $payload = array_intersect_key($snapshot, array_flip(['messages', 'responding', 'activeMessageId',
                        'generationStatus', 'generationMessageId', 'audioRequestIds',
                        'submissionId', 'turnStatus', 'rollbackConfirmed',
                    ]));
                    if (is_string($effects['restoredMessage'] ?? null)
                        && array_key_exists('generationMessageId', $effects)
                        && ($effects['generationMessageId'] ?? '') === ($state['messageId'] ?? '')
                        && ($effects['generationStatus'] ?? '') === ($state['status'] ?? '')) {
                        $payload['restoredMessage'] = $effects['restoredMessage'];
                    }
                    $this->event('chat.snapshot', [...$payload, 'threadId' => $this->authorization['threadId'],
                        'sessionId' => $this->authorization['sessionId'],
                    ]);
                    if ($this->closed) {
                        return;
                    }
                    $this->initialized = true;
                    $this->busy = false;
                    $this->snapshotRunning = false;
                    $this->opened->resolve($this->output);
                    $this->schedule();
                },
                fn () => $this->close('generation_unavailable'),
            );
        }, fn () => $this->close('snapshot_failed'));
    }

    private function checkGeneration(): void
    {
        if (! $this->valid() || ! $this->initialized || $this->checking) {
            return;
        }
        $this->checking = true;
        $this->redis->generation($this->authorization['userId'], $this->authorization['threadId'])->then(
            function (array $state): void {
                $this->checking = false;
                if ($this->valid() && self::signature($state) !== $this->generation) {
                    $this->requestSnapshot();
                }
            },
            fn () => $this->close('generation_unavailable'),
        );
    }

    private function checkRemember(): void
    {
        if (! $this->valid() || $this->checkingRemember) {
            return;
        }
        $this->checkingRemember = true;
        $remember = $this->authorization['remember'];
        $this->redis->remember($remember['id'])->then(function (?array $data) use ($remember): void {
            $this->checkingRemember = false;
            if (! $this->valid()) {
                return;
            }
            if (! RememberSession::validRecord($data, (int) ($this->clock)())
                || $data['user_id'] !== $this->authorization['userId'] || $data['expires'] !== $remember['expires']) {
                $this->close('remember_revoked');
            }
        }, fn () => $this->close('remember_unavailable'));
    }

    /** @param array<string, mixed> $payload */
    private function event(string $name, array $payload): void
    {
        $this->send('event: ' . $name . "\n" . 'data: '
            . json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n");
    }

    private function send(string $frame): void
    {
        if (! $this->valid()) {
            return;
        }
        if (strlen($frame) + $this->bytes > $this->clientLimit || ! $this->output->send($frame)) {
            $this->close('output_buffer_limit');
            return;
        }
        if ($this->output->blocked() && $this->writeTimer === null) {
            $this->writeTimer = $this->loop->addTimer(
                $this->config->get('write_timeout'),
                fn () => $this->close('write_timeout')
            );
        }
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array{string, string}
     */
    private static function signature(array $state): array
    {
        return [$state['messageId'] ?? '', $state['status'] ?? ''];
    }
}
