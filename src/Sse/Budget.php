<?php

declare(strict_types=1);

namespace App\Sse;

final class Budget
{
    private int $bytes = 0;

    public function __construct(private readonly int $limit)
    {
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function acquire(int $bytes): bool
    {
        if ($bytes < 0 || $bytes > $this->limit - $this->bytes) {
            return false;
        }
        $this->bytes += $bytes;
        return true;
    }

    public function release(int $bytes): void
    {
        $this->bytes -= $bytes;
        if ($this->bytes < 0) {
            throw new \LogicException('Unbalanced SSE buffer accounting');
        }
    }
}
