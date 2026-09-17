<?php

declare(strict_types=1);

namespace App\Test\Unit\Console;

use App\Console\ChatMaintenanceCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class ChatMaintenanceCommandTest extends TestCase
{
    public function testInvalidArgumentsNeverResolveStorage(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $tester = new CommandTester(new ChatMaintenanceCommand($container));
        foreach ([[], ['--user' => 'user'], ['--thread' => 'thread'],
            ['--compact' => true, '--reconcile' => true],
            ['--recover' => true, '--compact' => true],
            ['--recover' => true, '--user' => 'user'],
            ['--compact' => true, '--user' => 'user'],
            ['--compact' => true, '--limit' => '-1'],
            ['--compact' => true, '--limit' => '0'],
            ['--compact' => true, '--limit' => '10001'],
            ['--compact' => true, '--retention-days' => '0'],
            ['--compact' => true, '--retention-days' => '1.5'],
            ['--compact' => true, '--cursor' => 'invalid'],
            ['--compact' => true, '--cursor' => '123'],
            ['--compact' => true, '--cursor' => 'redis:123'],
            ['--compact' => true, '--cursor' => 'sql:v1:' . base64_encode('[1,"invalid"]')],
            ['--user' => 'user', '--thread' => 'thread', '--cursor' => '123'],
            ['--user' => 'user', '--thread' => 'thread', '--apply' => true],
        ] as $arguments) {
            self::assertSame(2, $tester->execute($arguments));
        }
    }

    public function testHelpExplainsSafetyWithoutResolvingStorage(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new ChatMaintenanceCommand($container));
        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput([
            'command' => 'chat:maintenance', '--help' => true,
        ]), $output));
        $help = $output->fetch();
        foreach (['--apply', '--compact', '--user', '--thread', '--reconcile', '--retention-days',
            '--cursor', 'dry-run', 'DB time', 'sql:v1:', 'revision CAS', 'without TTL',
            'attempted=1', 'No re-enqueue', 'SQL retention never scans Redis',
            'ALL Redis job payloads', 'matches the current messageId and owner',
        ] as $text) {
            self::assertStringContainsString($text, $help);
        }
        foreach (['--import-redis', '--complete-import', '--writers-stopped', 'Redis TIME', 'outbox'] as $removed) {
            self::assertStringNotContainsString($removed, $help);
        }
    }
}
