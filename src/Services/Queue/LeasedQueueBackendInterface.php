<?php

declare(strict_types=1);

namespace App\Services\Queue;

interface LeasedQueueBackendInterface extends QueueBackendInterface
{
    public function fail(QueueMessage $queueMessage): void;

    public function defer(QueueMessage $queueMessage): void;

    /** @param callable(): void $operation */
    public function withLease(QueueMessage $queueMessage, callable $operation): void;
}
