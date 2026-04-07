<?php

declare(strict_types=1);

namespace App\Queue;

interface QueueBackendInterface extends QueueDispatcherInterface
{
    public function reserveNextAvailable(
        string $queueName,
    ): ?QueueMessage;

    public function delete(QueueMessage $queueMessage): void;
}
