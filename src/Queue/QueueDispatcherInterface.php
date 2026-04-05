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
        ?\DateTimeImmutable $availableAt = null,
        ?int $maxAttempts = null,
    ): string;
}
