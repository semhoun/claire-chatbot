<?php

declare(strict_types=1);

namespace App\Services\Queue;

final readonly class QueueWorkerOptions
{
    public function __construct(
        public string $queueName,
        public int $timeout,
        public bool $once,
        public int $maxJobs,
        public int $maxTime,
    ) {
    }
}
