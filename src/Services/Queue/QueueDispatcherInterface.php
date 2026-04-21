<?php

declare(strict_types=1);

namespace App\Services\Queue;

interface QueueDispatcherInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(
        string $jobClass,
        array $payload,
        string $queue,
    ): string;
}
