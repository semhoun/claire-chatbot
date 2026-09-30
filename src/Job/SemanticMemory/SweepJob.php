<?php

declare(strict_types=1);

namespace App\Job\SemanticMemory;

use App\Services\Queue\QueueDoer;
use App\Services\SemanticMemoryService;
use Psr\Container\ContainerInterface;

final readonly class SweepJob implements QueueDoer
{
    public function __construct(private SemanticMemoryService $semanticMemoryService)
    {
    }

    public static function make(ContainerInterface $container): self
    {
        return new self($container->get(SemanticMemoryService::class));
    }

    public function handle(array $payload): void
    {
        $this->semanticMemoryService->sweep();
    }
}
