<?php

declare(strict_types=1);

namespace App\Services\Queue;

interface QueueBackendInterface extends QueueDispatcherInterface
{
    public function reserveNextAvailable(
        string $queueName,
        int $timeout = 5,
    ): ?QueueMessage;

    /** Acknowledge successful execution, using the reservation's ownership metadata. */
    public function delete(QueueMessage $queueMessage): void;

    /** Schedule a failed reservation for retry; durable backends may delay or dead-letter it. */
    public function release(QueueMessage $queueMessage): void;
}
