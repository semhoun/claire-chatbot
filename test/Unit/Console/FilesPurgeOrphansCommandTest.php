<?php

declare(strict_types=1);

namespace App\Test\Unit\Console;

use App\Console\FilesPurgeOrphansCommand;
use App\Services\OrphanFilePurger;
use App\Services\Settings;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class FilesPurgeOrphansCommandTest extends TestCase
{
    public function testInvalidArgumentsNeverResolveStorage(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $commandTester = new CommandTester(new FilesPurgeOrphansCommand($container));
        foreach (['', '0', '-1', '876001', '1.5', '1e2', 'abc', ' 24', '24 ', "24\n",
            '999999999999999999999999999999999999',
        ] as $age) {
            self::assertSame(2, $commandTester->execute(['--min-age-hours' => $age]));
            self::assertStringContainsString('min-age-hours must be between 1 and 876000.', $commandTester->getDisplay());
        }

        self::assertSame(2, $commandTester->execute(['--apply' => true]));
        self::assertStringContainsString('Stop uploads and workers, then confirm with --writers-stopped.',
            $commandTester->getDisplay());
    }

    public function testHelpExplainsSafetyWithoutResolvingStorage(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new FilesPurgeOrphansCommand($container));

        $bufferedOutput = new BufferedOutput();

        self::assertSame(0, $application->run(new ArrayInput([
            'command' => 'files:purge-orphans', '--help' => true,
        ]), $bufferedOutput));
        $help = $bufferedOutput->fetch();
        foreach (['--apply', '--writers-stopped', '--min-age-hours', 'Dry-run by default',
            'Uploads without a conversation are valid', 'Telegram storage', 'NOT a lock',
            'stop ALL file writers', 'Database records are removed first', 'partial progress',
            'No conversation, user, Redis state or RAG/vector data',
        ] as $text) {
            self::assertStringContainsString($text, $help);
        }
    }

    #[DataProvider('executionModes')]
    public function testExecutionUsesRealPurgerAndReportsJson(array $arguments, bool $apply, bool $eligible): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE account (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE chat_history (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE file (id INTEGER PRIMARY KEY,
            user_id INTEGER, history_id INTEGER, file_path TEXT, created_at TEXT)');
        $old = time() - 48 * 3600;
        $connection->insert('file', ['id' => 1, 'user_id' => 999, 'history_id' => null,
            'file_path' => 'generated/orphan.txt', 'created_at' => date('Y-m-d H:i:s', $old)]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('listContents')->willReturnCallback(
            static fn (string $root): DirectoryListing => new DirectoryListing($root === 'generated'
                ? [new FileAttributes('generated/orphan.txt', lastModified: $old)] : []),
        );
        $filesystem->expects($apply && $eligible ? self::once() : self::never())
            ->method('lastModified')->with('generated/orphan.txt')->willReturn($old);
        $filesystem->expects($apply && $eligible ? self::once() : self::never())
            ->method('delete')->with('generated/orphan.txt');
        $orphanFilePurger = new OrphanFilePurger($entityManager, $filesystem,
            new Settings(['files' => ['upload' => ['path' => 'uploads']]]));
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(OrphanFilePurger::class)->willReturn($orphanFilePurger);
        $commandTester = new CommandTester(new FilesPurgeOrphansCommand($container));

        try {
            self::assertSame(0, $commandTester->execute($arguments));
            self::assertSame(['apply' => $apply, 'orphan_rows' => (int) $eligible,
                'deleted_rows' => (int) ($apply && $eligible), 'orphan_files' => (int) $eligible,
                'deleted_files' => (int) ($apply && $eligible),
            ], json_decode(trim($commandTester->getDisplay()), true, 512, JSON_THROW_ON_ERROR));
            self::assertSame($apply && $eligible ? 0 : 1, $connection->fetchOne('SELECT COUNT(*) FROM file'));
        } finally {
            $connection->close();
        }
    }

    public static function executionModes(): iterable
    {
        yield 'default dry run' => [[], false, true];
        yield 'confirmation alone remains dry run' => [['--writers-stopped' => true], false, true];
        yield 'apply' => [['--apply' => true, '--writers-stopped' => true], true, true];
        yield 'minimum age' => [['--min-age-hours' => '1'], false, true];
        yield 'custom age is honored' => [['--apply' => true, '--writers-stopped' => true,
            '--min-age-hours' => '72'], true, false];
        yield 'maximum age' => [['--min-age-hours' => '876000'], false, false];
    }

    public function testDependencyFailureReturnsNonzeroWithoutLeakingDetails(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(OrphanFilePurger::class)
            ->willThrowException(new \RuntimeException('secret database credentials'));
        $commandTester = new CommandTester(new FilesPurgeOrphansCommand($container));

        self::assertSame(1, $commandTester->execute([]));
        self::assertStringContainsString('Purge failed:', $commandTester->getDisplay());
        self::assertStringContainsString('Partial progress is possible.', $commandTester->getDisplay());
        self::assertStringNotContainsString('secret database credentials', $commandTester->getDisplay());
    }

    public function testPurgerConfigurationFailureReturnsNonzero(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getConnection');
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::never())->method('listContents');
        $orphanFilePurger = new OrphanFilePurger($entityManager, $filesystem,
            new Settings(['files' => ['upload' => ['path' => '../unsafe']]]));
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($orphanFilePurger);
        $commandTester = new CommandTester(new FilesPurgeOrphansCommand($container));

        self::assertSame(1, $commandTester->execute(['--apply' => true, '--writers-stopped' => true]));
        self::assertStringContainsString('invalid storage configuration', $commandTester->getDisplay());
    }
}
