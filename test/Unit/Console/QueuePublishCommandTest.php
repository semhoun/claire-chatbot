<?php

declare(strict_types=1);

namespace App\Test\Unit\Console;

use App\Console\QueuePublishCommand;
use App\Services\Queue\SqlOutboxQueueBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class QueuePublishCommandTest extends TestCase
{
    public static function invalidOptions(): iterable
    {
        foreach (['0', '-1', '1001', '10bad', ''] as $limit) {
            yield [['--limit' => $limit]];
        }
        yield [['--queue' => '']];
        yield [['--queue' => 'sql-outbox:default']];
        yield [['--queue' => str_repeat('q', 256)]];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidArgumentsDoNotResolveStorage(array $options): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $tester = new CommandTester(new QueuePublishCommand($container));
        self::assertSame(2, $tester->execute($options));
    }

    public function testHelpDoesNotConnectToStorage(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        self::assertStringContainsString('not a dry-run', new QueuePublishCommand($container)->getHelp());
    }

    public function testOneBatchReportsPublicationFailureWithoutClaimingDelivery(): void
    {
        $publisher = new class {
            public array $calls = [];
            public function publishPending(?string $queue, int $limit): array
            {
                $this->calls[] = [$queue, $limit];
                return ['selected' => 2, 'published' => 1, 'failed' => 1, 'skipped' => 0];
            }
        };
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(SqlOutboxQueueBackend::class)->willReturn($publisher);
        $tester = new CommandTester(new QueuePublishCommand($container));
        self::assertSame(1, $tester->execute(['--queue' => 'telegram', '--limit' => '2']));
        self::assertSame([['telegram', 2]], $publisher->calls);
        self::assertSame(['queue' => 'telegram', 'selected' => 2, 'published' => 1, 'failed' => 1, 'skipped' => 0],
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testDefaultBatchAndEmptyOutboxSucceed(): void
    {
        $publisher = new class {
            public array $calls = [];
            public function publishPending(?string $queue, int $limit): array
            {
                $this->calls[] = [$queue, $limit];
                return ['selected' => 0, 'published' => 0, 'failed' => 0, 'skipped' => 0];
            }
        };
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($publisher);
        $tester = new CommandTester(new QueuePublishCommand($container));
        self::assertSame(0, $tester->execute([]));
        self::assertSame([[null, 100]], $publisher->calls);
    }

    public function testStorageExceptionsDoNotExposePayloadsOrCredentials(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('PRIVATE PAYLOAD PASSWORD'));
        $tester = new CommandTester(new QueuePublishCommand($container));
        self::assertSame(1, $tester->execute([]));
        self::assertStringNotContainsString('PRIVATE', $tester->getDisplay());
        self::assertStringNotContainsString('PASSWORD', $tester->getDisplay());
    }
}
