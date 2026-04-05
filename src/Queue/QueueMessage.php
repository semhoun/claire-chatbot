<?php

declare(strict_types=1);

namespace App\Queue;

final readonly class QueueMessage
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $jobClass,
        public array $payload,
        public string $queueName,
        public array $metadata = [],
    ) {
    }
}
