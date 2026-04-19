<?php

declare(strict_types=1);

namespace App\Services\Queue;

interface QueueBackendInterface extends QueueDispatcherInterface
{
    public function reserveNextAvailable(
        string $queueName,
        int $timeout = 5,
    ): ?QueueMessage;

    public function delete(QueueMessage $queueMessage): void;
}
