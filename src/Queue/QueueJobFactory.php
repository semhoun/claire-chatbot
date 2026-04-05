<?php

declare(strict_types=1);

namespace App\Queue;

use Psr\Container\ContainerInterface;
use RuntimeException;

final readonly class QueueJobFactory
{
    public function __construct(
        private ContainerInterface $container
    ) {
    }

    public function createQueueDoer(QueueMessage $job): QueueDoer
    {
        $jobClass = $job->jobClass;

        if (! class_exists($jobClass)) {
            throw new RuntimeException(sprintf('Queue job class "%s" does not exist', $jobClass));
        }

        if (! is_a($jobClass, QueueDoer::class, true)) {
            throw new RuntimeException(sprintf('Queue job class "%s" must implement %s', $jobClass, QueueDoer::class));
        }

        if (! method_exists($jobClass, 'make')) {
            throw new RuntimeException(sprintf('Queue job class "%s" must define static make()', $jobClass));
        }

        $queueDoer = $jobClass::make($this->container);

        if (! $queueDoer instanceof QueueDoer) {
            throw new RuntimeException(sprintf('Queue job class "%s" returned an invalid queue doer', $jobClass));
        }

        return $queueDoer;
    }

}
