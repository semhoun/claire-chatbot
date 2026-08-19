<?php

declare(strict_types=1);

namespace App\Services\Rendering;

use App\Entity\File;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GeneratedFileProcessor
{
    private const string IMAGE_PLACEHOLDER_DATA_URI =
        'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    public function __construct(
        private Settings $settings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(string $content): string
    {
        if (preg_match(File::GENERATED_FILE_PATTERN, $content) !== 1) {
            return $content;
        }

        $entityRepository = $this->entityManager->getRepository(File::class);
        $baseUrl = $this->settings->get('base_url');

        return preg_replace_callback(
            File::GENERATED_FILE_PATTERN,
            static function (array $matches) use ($baseUrl, $entityRepository): string {
                $prefix = $matches[1] ?? '';
                $suffix = $matches[3] ?? '';
                $fileId = str_replace(['"', "'"], ['', ''], $matches[2]);
                $file = $entityRepository->findOneBy(['fileId' => $fileId]);
                if (! $file instanceof File) {
                    return $matches[0];
                }

                $url = $baseUrl . '/files/serve/' . $file->getFileId();
                if ($prefix !== '') {
                    if ($file->fileType() === File::FILE_TYPE_IMAGE) {
                        return $prefix . '"' . self::IMAGE_PLACEHOLDER_DATA_URI
                            . '" data-protected-src="' . $url
                            . '" class="claire-generated-image"' . $suffix;
                    }

                    return $prefix . '"' . $url
                        . '" class="claire-generated-file"' . $suffix;
                }

                if ($file->fileType() === File::FILE_TYPE_IMAGE) {
                    return '<img data-protected-src="' . $url . '" src="'
                        . self::IMAGE_PLACEHOLDER_DATA_URI
                        . '" alt="Generated image" class="claire-generated-image">';
                }

                return '<a href="' . $url
                    . '" class="claire-generated-file" target="_blank">'
                    . htmlspecialchars($file->getFilename(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</a>';
            },
            $content
        ) ?? $content;
    }

    public function processPlaceholder(string $content): string
    {
        return preg_replace_callback(
            File::GENERATED_FILE_PATTERN,
            static function (array $matches): string {
                $prefix = $matches[1] ?? '';
                if ($prefix === '' || str_starts_with($prefix, '<img')) {
                    return '<span class="claire-generated-image-placeholder" '
                        . 'aria-label="Image générée">&#128247;</span>';
                }

                return $matches[0];
            },
            $content
        ) ?? $content;
    }
}
