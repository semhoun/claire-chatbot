<?php

declare(strict_types=1);

namespace App\Test\Unit\Queue;

use App\Queue\QueueJobFactory;
use App\Queue\QueueMessage;
use App\Queue\QueueDoer;
use App\Queue\QueueSerializer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
final class QueueJobFactoryTest extends TestCase
{
    public function testCreatesQueueDoerFromPayload(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $factory = new QueueJobFactory($container, new QueueSerializer());
        $job = new QueueMessage(
            id: 'job-1',
            jobClass: TestQueueDoer::class,
            payload: ['message' => 'hello'],
            queueName: 'default',
        );

        $queueDoer = $factory->createQueueDoer($job);

        $this->assertInstanceOf(TestQueueDoer::class, $queueDoer);
    }

    public function testThrowsWhenClassDoesNotImplementQueueContract(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $factory = new QueueJobFactory($container, new QueueSerializer());
        $job = new QueueMessage(
            id: 'job-2',
            jobClass: TestInvalidClass::class,
            payload: [],
            queueName: 'default',
        );

        $this->expectException(RuntimeException::class);

        $factory->createQueueDoer($job);
    }
}

final class TestQueueDoer implements QueueDoer
{
    public static function make(ContainerInterface $container): self
    {
        return new self();
    }

    public function handle(array $payload): void
    {
    }
}

final class TestInvalidClass
{
}
