<?php

declare(strict_types=1);

namespace App\Queue;

interface QueueDispatcherInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(
        string $jobClass,
        array $payload = [],
        string $queue = 'default',
    ): string;
}
