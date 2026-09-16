<?php

declare(strict_types=1);

namespace App\Services\Rendering;

use App\Entity\File;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GeneratedFileProcessor
{
    public function __construct(
        private Settings $settings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<int, array{id:string, name:string, type:string, url:?string}> */
    public function resolve(string $content, string $userId, bool $pending = false): array
    {
        preg_match_all(
            '/@@GENERATED@@[a-zA-Z0-9_@\-.]*@@|'
            . '@@GENERATED@@[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}@(?![a-zA-Z0-9_@\-])/',
            $content,
            $matches,
        );
        if (trim($userId) === '' || $matches[0] === []) {
            return [];
        }

        $files = [];
        $repository = $this->entityManager->getRepository(File::class);
        // Repair only the complete UUID variant before deduplication and owner lookup.
        $ids = array_map(static fn (string $id): string => str_ends_with($id, '@@') ? $id : $id . '@', $matches[0]);
        foreach (array_unique($ids) as $id) {
            $file = $repository->findOneBy(['fileId' => $id, 'user' => $userId]);
            if (! $file instanceof File) {
                if ($pending) {
                    $files[] = [
                        'id' => $id, 'name' => 'Fichier en cours de génération',
                        'type' => 'pending', 'url' => null,
                    ];
                }
                continue;
            }

            $files[] = [
                'id' => $id,
                'name' => $file->getFilename(),
                'type' => $file->fileType(),
                'url' => rtrim($this->settings->get('base_url'), '/')
                    . '/files/serve/' . rawurlencode($file->getFileId()),
            ];
        }

        return $files;
    }
}
