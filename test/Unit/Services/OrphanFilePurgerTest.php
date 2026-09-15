<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\OrphanFilePurger;
use App\Services\Settings;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrphanFilePurgerTest extends TestCase
{
    private Connection $connection;

    private Filesystem $filesystem;

    private string $directory;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE account (id INTEGER PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE chat_history (id INTEGER PRIMARY KEY)');
        $this->connection->executeStatement('CREATE TABLE file (id INTEGER PRIMARY KEY,
            user_id INTEGER, history_id INTEGER, file_path TEXT, created_at TEXT)');
        $this->connection->insert('account', ['id' => 1]);
        $this->connection->insert('chat_history', ['id' => 1]);

        $this->directory = sys_get_temp_dir() . '/claire-orphan-purger-' . bin2hex(random_bytes(8));
        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($this->directory));
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory('');
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        $this->connection->close();
    }

    public function testDryRunAndApplyPreserveValidRecentSharedAndExcludedFiles(): void
    {
        $this->addRow(1, 999, null, 'uploads/missing-user.txt');
        $this->addRow(2, 1, 999, 'generated/missing-history.txt');
        $this->addRow(3, 1, null, 'uploads/valid-upload.txt');
        $this->addRow(4, 1, 1, 'generated/valid-reference.txt');
        $this->addRow(5, 999, null, 'uploads/recent-row.txt', 1);
        $this->addRow(6, 999, null, 'uploads/shared.txt');
        $this->addRow(7, 1, null, 'uploads/shared.txt');
        $this->addRow(8, 999, null, 'uploads/recent-file.txt');
        $this->addRow(9, 999, null, 'uploads/unknown-date.txt');
        $this->connection->update('file', ['created_at' => null], ['id' => 9]);
        $paths = [
            'uploads/missing-user.txt', 'generated/missing-history.txt',
            'uploads/valid-upload.txt', 'generated/valid-reference.txt',
            'uploads/recent-row.txt', 'uploads/shared.txt', 'uploads/recent-file.txt',
            'uploads/unknown-date.txt', 'generated/nested/unreferenced.txt',
            'uploads/unreferenced.txt', 'uploads/recent-unreferenced.txt',
            'telegram/unreferenced.txt', 'other/unreferenced.txt',
        ];
        foreach ($paths as $path) {
            $this->addFile($path, str_contains($path, 'recent-file')
                || str_contains($path, 'recent-unreferenced') ? 1 : 72);
        }

        $before = $this->connection->fetchAllAssociative('SELECT * FROM file ORDER BY id');
        $purger = $this->purger();

        self::assertSame($this->purgeResult(4, 0, 4, 0), $purger->purge(false));
        self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM file ORDER BY id'));
        foreach ($paths as $path) {
            self::assertSame('contents', $this->filesystem->read($path));
        }

        self::assertSame($this->purgeResult(4, 4, 4, 4), $purger->purge(true));
        self::assertSame([3, 4, 5, 7, 9], $this->connection->fetchFirstColumn('SELECT id FROM file ORDER BY id'));
        $deleted = ['uploads/missing-user.txt', 'generated/missing-history.txt',
            'generated/nested/unreferenced.txt', 'uploads/unreferenced.txt'];
        foreach ($paths as $path) {
            self::assertSame(! in_array($path, $deleted, true), $this->filesystem->fileExists($path), $path);
        }

        self::assertSame([1], $this->connection->fetchFirstColumn('SELECT id FROM account'));
        self::assertSame([1], $this->connection->fetchFirstColumn('SELECT id FROM chat_history'));
        self::assertSame($this->purgeResult(0, 0, 0, 0), $purger->purge(true));
    }

    public function testKeysetPaginationProcessesMoreThanOnePage(): void
    {
        for ($id = 1; $id <= 501; ++$id) {
            $this->addRow($id, 999, null, 'uploads/shared-orphan.txt');
        }

        $this->addFile('uploads/shared-orphan.txt');

        self::assertSame($this->purgeResult(501, 0, 1, 0), $this->purger()->purge(false));
        self::assertSame(501, $this->connection->fetchOne('SELECT COUNT(*) FROM file'));
        self::assertSame($this->purgeResult(501, 501, 1, 1), $this->purger()->purge(true));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM file'));
    }

    public function testConfiguredRootIsScannedAndDuplicateRootsAreCountedOnce(): void
    {
        $this->addFile('custom/uploads/orphan.txt');
        $this->addFile('uploads/not-configured.txt');
        $this->addFile('generated/orphan.txt');

        self::assertSame($this->purgeResult(0, 0, 2, 0), $this->purger(root: 'custom/uploads')->purge(false));
        self::assertSame($this->purgeResult(0, 0, 1, 0), $this->purger(root: 'generated')->purge(false));
        self::assertSame($this->purgeResult(0, 0, 2, 2), $this->purger(root: 'custom/uploads')->purge(true));
        self::assertTrue($this->filesystem->fileExists('uploads/not-configured.txt'));
    }

    public function testNestedUploadRootIsCountedOnceInDryRunAndApply(): void
    {
        $this->addFile('generated/uploads/orphan.txt');
        $purger = $this->purger(root: 'generated/uploads');

        self::assertSame($this->purgeResult(0, 0, 1, 0), $purger->purge(false));
        self::assertTrue($this->filesystem->fileExists('generated/uploads/orphan.txt'));
        self::assertSame($this->purgeResult(0, 0, 1, 1), $purger->purge(true));
        self::assertFalse($this->filesystem->fileExists('generated/uploads/orphan.txt'));
    }

    #[DataProvider('symbolicLinkRoots')]
    public function testSymbolicLinkInRootIsRejectedBeforeDeletingRows(string $root, string $link): void
    {
        $this->addRow(1, 999, null, $root . '/orphan.txt');
        $before = $this->connection->fetchAllAssociative('SELECT * FROM file');
        $externalDirectory = $this->directory . '-external';
        $externalFilesystem = new Filesystem(new LocalFilesystemAdapter($externalDirectory));
        $linkPath = $this->directory . '/' . $link;

        try {
            $externalFilesystem->write('orphan.txt', 'external contents');
            self::assertTrue(touch($externalDirectory . '/orphan.txt', time() - 72 * 3600));
            if (dirname($link) !== '.') {
                $this->filesystem->createDirectory(dirname($link));
            }

            self::assertTrue(symlink($externalDirectory, $linkPath));
            clearstatcache();
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager->expects(self::never())->method('getConnection');
            $orphanFilePurger = new OrphanFilePurger($entityManager, $this->filesystem, new Settings([
                'files' => ['fileSystem' => ['path' => $this->directory], 'upload' => ['path' => $root]],
            ]));

            foreach ([false, true] as $apply) {
                try {
                    $orphanFilePurger->purge($apply);
                    self::fail('Symbolic link in storage root was accepted.');
                } catch (InvalidArgumentException $exception) {
                    self::assertSame('Symbolic link in storage directory.', $exception->getMessage());
                }

                self::assertSame($before, $this->connection->fetchAllAssociative('SELECT * FROM file'));
                self::assertSame('external contents', $externalFilesystem->read('orphan.txt'));
                self::assertTrue(is_link($linkPath));
            }
        } finally {
            if (is_link($linkPath)) {
                unlink($linkPath);
            }

            $externalFilesystem->deleteDirectory('');
            if (is_dir($externalDirectory)) {
                rmdir($externalDirectory);
            }
        }
    }

    public static function symbolicLinkRoots(): iterable
    {
        yield 'generated root' => ['uploads', 'generated'];
        yield 'upload root' => ['uploads', 'uploads'];
        yield 'intermediate upload component' => ['uploads/nested', 'uploads'];
        yield 'nested upload root' => ['custom/uploads', 'custom/uploads'];
        yield 'nested generated upload root' => ['generated/uploads', 'generated/uploads'];
    }

    #[DataProvider('unsafeRoots')]
    public function testUnsafeRootIsRejectedBeforeDeletingRows(string $root): void
    {
        $this->addRow(1, 999, null, 'uploads/orphan.txt');
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::never())->method('listContents');

        try {
            $this->purger($filesystem, $root)->purge(true);
            self::fail('Unsafe root was accepted.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            self::assertSame('Unsafe upload directory.', $invalidArgumentException->getMessage());
        }

        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM file'));
    }

    public static function unsafeRoots(): iterable
    {
        foreach (['', '/', '/uploads', '../uploads', 'uploads/..', './uploads', 'uploads/.',
            'uploads//nested', 'uploads/', 'uploads\\nested', "uploads\0bad", "uploads\nbad",
            "uploads\x7fbad", 'telegram', 'telegram/uploads',
        ] as $root) {
            yield [$root];
        }
    }

    #[DataProvider('invalidAges')]
    public function testInvalidAgeIsRejectedBeforeAccessingDependencies(int $age): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getConnection');
        $orphanFilePurger = new OrphanFilePurger($entityManager, $this->filesystem, new Settings([]));

        $this->expectException(InvalidArgumentException::class);
        $orphanFilePurger->purge(true, $age);
    }

    public static function invalidAges(): iterable
    {
        yield [-1];
        yield [0];
        yield [876001];
    }

    public function testListingFiltersUnsafeUnknownRecentAndOutOfRootEntries(): void
    {
        $old = time() - 72 * 3600;
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::exactly(2))->method('listContents')->willReturnCallback(
            static fn (string $root, bool $recursive): DirectoryListing => new DirectoryListing(
                $root === 'generated' && $recursive ? [
                    new DirectoryAttributes('generated/directory'),
                    new FileAttributes('generated/unknown.txt'),
                    new FileAttributes('generated/recent.txt', lastModified: time()),
                    new FileAttributes('generated/../outside.txt', lastModified: $old),
                    new FileAttributes('generated/unsafe\\file.txt', lastModified: $old),
                    new FileAttributes('generated-other/file.txt', lastModified: $old),
                    new FileAttributes('telegram/file.txt', lastModified: $old),
                    new FileAttributes('generated/old.txt', lastModified: $old),
                ] : [],
            ),
        );
        $filesystem->expects(self::once())->method('lastModified')->with('generated/old.txt')->willReturn(time());
        $filesystem->expects(self::never())->method('delete');

        self::assertSame($this->purgeResult(0, 0, 1, 0), $this->purger($filesystem)->purge(true));
    }

    public function testListingFailurePropagatesAfterRowsAreDeleted(): void
    {
        $this->addRow(1, 999, null, 'uploads/orphan.txt');
        $this->addFile('uploads/orphan.txt');
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::once())->method('listContents')->with('generated', true)->willThrowException(
            UnableToListContents::atLocation('generated', true, new \RuntimeException('Storage unavailable')),
        );

        try {
            $this->purger($filesystem)->purge(true);
            self::fail('Listing failure was swallowed.');
        } catch (UnableToListContents) {
            self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM file'));
            self::assertTrue($this->filesystem->fileExists('uploads/orphan.txt'));
        }

        self::assertSame($this->purgeResult(0, 0, 1, 1), $this->purger()->purge(true));
    }

    public function testDeletionFailurePropagates(): void
    {
        $old = time() - 72 * 3600;
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('listContents')->willReturn(new DirectoryListing([
            new FileAttributes('generated/orphan.txt', lastModified: $old),
        ]));
        $filesystem->method('lastModified')->willReturn($old);
        $filesystem->expects(self::once())->method('delete')->with('generated/orphan.txt')
            ->willThrowException(UnableToDeleteFile::atLocation('generated/orphan.txt'));

        $this->expectException(UnableToDeleteFile::class);
        $this->purger($filesystem)->purge(true);
    }

    public function testDatabaseFailurePropagatesWithoutScanningStorage(): void
    {
        $this->connection->executeStatement('DROP TABLE file');
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->expects(self::never())->method('listContents');

        $this->expectException(Exception::class);
        $this->purger($filesystem)->purge(true);
    }

    private function purger(?Filesystem $filesystem = null, string $root = 'uploads'): OrphanFilePurger
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($this->connection);

        return new OrphanFilePurger($entityManager, $filesystem ?? $this->filesystem,
            new Settings(['files' => ['upload' => ['path' => $root]]]));
    }

    private function addRow(int $id, int $user, ?int $history, string $path, int $age = 72): void
    {
        $this->connection->insert('file', [
            'id' => $id, 'user_id' => $user, 'history_id' => $history, 'file_path' => $path,
            'created_at' => date('Y-m-d H:i:s', time() - $age * 3600),
        ]);
    }

    private function addFile(string $path, int $age = 72): void
    {
        $this->filesystem->write($path, 'contents');
        self::assertTrue(touch($this->directory . '/' . $path, time() - $age * 3600));
        clearstatcache();
    }

    /** @return array{orphan_rows: int, deleted_rows: int, orphan_files: int, deleted_files: int} */
    private function purgeResult(int $rows, int $deletedRows, int $files, int $deletedFiles): array
    {
        return ['orphan_rows' => $rows, 'deleted_rows' => $deletedRows,
            'orphan_files' => $files, 'deleted_files' => $deletedFiles];
    }
}
