<?php

declare(strict_types=1);

namespace App\Sse;

use Evenement\EventEmitter;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

/** A single-frame source: pause propagates back to the connection state machine. */
final class Output extends EventEmitter implements ReadableStreamInterface
{
    private bool $readable = true;
    private bool $paused = true;
    private ?string $frame = null;
    private int $bytes = 0;

    public function __construct(private readonly Budget $budget, private readonly int $limit)
    {
    }

    public function send(string $frame): bool
    {
        if (! $this->readable || $this->bytes !== 0 || strlen($frame) > $this->limit
            || ! $this->budget->acquire(strlen($frame))) {
            return false;
        }
        $this->bytes = strlen($frame);
        $this->frame = $frame;
        $this->flush();
        return true;
    }

    public function blocked(): bool
    {
        return $this->bytes !== 0;
    }

    public function bufferedBytes(): int
    {
        return $this->bytes;
    }

    public function isReadable(): bool
    {
        return $this->readable;
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        if (! $this->readable) {
            return;
        }
        $this->paused = false;
        if ($this->frame === null) {
            $this->release();
        }
        $this->flush();
        if (! $this->blocked()) {
            $this->emit('drain');
        }
    }

    /** @param array{end?: bool} $options */
    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface
    {
        $result = Util::pipe($this, $dest, $options);
        $this->resume();
        return $result;
    }

    public function close(): void
    {
        if (! $this->readable) {
            return;
        }
        $this->readable = false;
        $this->frame = null;
        $this->release();
        $this->emit('close');
        $this->removeAllListeners();
    }

    private function flush(): void
    {
        if ($this->paused || $this->frame === null || ! $this->readable) {
            return;
        }
        $frame = $this->frame;
        $this->frame = null;
        $this->emit('data', [$frame]);
        // Retain accounting while the downstream socket is waiting for drain.
        if (! $this->paused) {
            $this->release();
        }
    }

    private function release(): void
    {
        $this->budget->release($this->bytes);
        $this->bytes = 0;
    }
}
