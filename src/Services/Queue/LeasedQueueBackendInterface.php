<?php

declare(strict_types=1);

namespace App\Services\Queue;

interface LeasedQueueBackendInterface extends QueueBackendInterface
{
    /** @param callable(): void $operation */
    public function withLease(QueueMessage $queueMessage, callable $operation): void;
}
