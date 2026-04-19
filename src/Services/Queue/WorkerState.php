<?php

declare(strict_types=1);

namespace App\Services\Queue;

final class WorkerState
{
    private readonly int $startedAt;

    private int $processedJobs = 0;

    private int $loopCount = 0;

    public function __construct()
    {
        $this->startedAt = time();
    }

    public function getStartedAt(): int
    {
        return $this->startedAt;
    }

    public function getProcessedJobs(): int
    {
        return $this->processedJobs;
    }

    public function incrementProcessedJobs(): void
    {
        ++$this->processedJobs;
    }

    public function getLoopCount(): int
    {
        return $this->loopCount;
    }

    public function incrementLoopCount(): void
    {
        ++$this->loopCount;
    }
}
