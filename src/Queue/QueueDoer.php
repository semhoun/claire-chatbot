<?php

declare(strict_types=1);

namespace App\Queue;

use Psr\Container\ContainerInterface;

interface QueueDoer
{
    public static function make(ContainerInterface $container): self;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void;
}
