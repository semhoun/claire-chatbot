<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\File;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;

final readonly class OrphanFilePurger
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        private Settings $settings,
    ) {}

    /** @return array{orphan_rows: int, deleted_rows: int, orphan_files: int, deleted_files: int} */
    public function purge(bool $apply, int $minAgeHours = 24): array
    {
        if ($minAgeHours < 1 || $minAgeHours > 876000) {
            throw new \InvalidArgumentException('Invalid minimum age.');
        }

        $roots = array_unique([
            File::GENERATED_FOLDER_PREFIX,
            (string) $this->settings->get('files.upload.path'),
        ]);
        foreach ($roots as $root) {
            if (! $this->isSafePath($root) || $root === 'telegram' || str_starts_with($root, 'telegram/')) {
                throw new \InvalidArgumentException('Unsafe upload directory.');
            }

            // Flysystem rejects links encountered inside a listing, but not its starting directory.
            $storagePath = $this->settings->get('files')['fileSystem']['path'] ?? null;
            if (is_string($storagePath) && $storagePath !== '') {
                foreach (explode('/', $root) as $part) {
                    $storagePath .= '/' . $part;
                    if (is_link($storagePath)) {
                        throw new \InvalidArgumentException('Symbolic link in storage directory.');
                    }
                }
            }
        }

        $cutoff = time() - $minAgeHours * 3600;
        $date = new \DateTimeImmutable()->setTimestamp($cutoff)->format('Y-m-d H:i:s');
        $connection = $this->entityManager->getConnection();
        $orphan = '(NOT EXISTS (SELECT 1 FROM account a WHERE a.id = f.user_id)'
            . ' OR (f.history_id IS NOT NULL AND NOT EXISTS'
            . ' (SELECT 1 FROM chat_history h WHERE h.id = f.history_id)))';
        $result = ['orphan_rows' => 0, 'deleted_rows' => 0, 'orphan_files' => 0, 'deleted_files' => 0];

        // Keyset pages avoid loading the complete file inventory into memory.
        $cursor = '0';
        do {
            $rows = $connection->fetchAllAssociative(
                'SELECT f.id FROM file f WHERE f.id > ? AND f.created_at < ? AND ' . $orphan
                . ' ORDER BY f.id ASC LIMIT 500',
                [$cursor, $date],
            );
            foreach ($rows as $row) {
                $cursor = (string) $row['id'];
                ++$result['orphan_rows'];
                if ($apply) {
                    // Recheck ownership before deleting; physical leftovers remain recoverable on the next run.
                    $result['deleted_rows'] += $connection->executeStatement(
                        'DELETE FROM file WHERE id = ? AND created_at < ?'
                        . ' AND (NOT EXISTS (SELECT 1 FROM account a WHERE a.id = file.user_id)'
                        . ' OR (history_id IS NOT NULL AND NOT EXISTS'
                        . ' (SELECT 1 FROM chat_history h WHERE h.id = file.history_id)))',
                        [$cursor, $date],
                    );
                }
            }
        } while (count($rows) === 500);

        foreach ($roots as $root) {
            if (str_starts_with($root, File::GENERATED_FOLDER_PREFIX . '/')) {
                continue;
            }

            foreach ($this->filesystem->listContents($root, true) as $entry) {
                $path = $entry->path();
                if (! $entry->isFile() || ! $this->isSafePath($path)
                    || ! str_starts_with($path, $root . '/')
                    || $entry->lastModified() === null || $entry->lastModified() >= $cutoff) {
                    continue;
                }

                // In simulation, ignore only the old orphan rows that would have been deleted above.
                $sql = 'SELECT COUNT(*) FROM file f WHERE f.file_path = ?';
                $parameters = [$path];
                if (! $apply) {
                    $sql .= ' AND (NOT ' . $orphan . ' OR f.created_at >= ? OR f.created_at IS NULL)';
                    $parameters[] = $date;
                }

                if ((int) $connection->fetchOne($sql, $parameters) !== 0) {
                    continue;
                }

                ++$result['orphan_files'];
                if ($apply && $this->filesystem->lastModified($path) < $cutoff) {
                    $this->filesystem->delete($path);
                    ++$result['deleted_files'];
                }
            }
        }

        return $result;
    }

    private function isSafePath(string $path): bool
    {
        return $path !== '' && ! str_contains($path, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1
            && array_all(explode('/', $path), static fn (string $part): bool => ! in_array($part, ['', '.', '..'], true));
    }
}
