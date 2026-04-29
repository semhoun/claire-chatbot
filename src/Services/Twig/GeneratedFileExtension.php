<?php

declare(strict_types=1);

namespace App\Services\Twig;

use App\Entity\File;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class GeneratedFileExtension extends AbstractExtension
{
    private const string IMAGE_PLACEHOLDER_DATA_URI =
        'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    public function __construct(
        protected readonly Settings $settings,
        protected readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<TwigFilter>
     */
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('process_generated_files', $this->processGeneratedFiles(...), ['is_safe' => ['html']]),
            new TwigFilter('process_generated_files_placeholder', $this->processGeneratedFilesPlaceholder(...), ['is_safe' => ['html']]),
        ];
    }

    public function processGeneratedFiles(string $content): string
    {
        $entityRepository = $this->entityManager->getRepository(File::class);
        $baseUrl = $this->settings->get('base_url');

        return preg_replace_callback(File::GENERATED_FILE_PATTERN, static function (array $matches) use ($baseUrl, $entityRepository): string {
            $prefix = $matches[1] ?? '';
            $suffix = $matches[3] ?? '';

            $fileId = str_replace(['"', "'"], ['', ''], $matches[2]);
            $file = $entityRepository->findOneBy(['fileId' => $fileId]);
            if ($file === null) {
                return $matches[0];
            }

            $fileUrl = $baseUrl . '/files/serve/' . $file->getFileId();

            if ($prefix !== '') {
                if ($file->fileType() === File::FILE_TYPE_IMAGE) {
                    return $prefix . '"' . self::IMAGE_PLACEHOLDER_DATA_URI . '" data-protected-src="' . $fileUrl . '" class="generated-image"' . $suffix;
                }

                return $prefix . '"' . $fileUrl . '"  class="generated-file"' . $suffix;
            }

            if ($file->fileType() === File::FILE_TYPE_IMAGE) {
                return '<img data-protected-src="' . $fileUrl . '" src="' . self::IMAGE_PLACEHOLDER_DATA_URI . '" alt="Generated image" class="generated-image">';
            }

            return '<a href="' . $fileUrl . '" class="generated-file" target="_blank">' . $file->getFilename() . '</a>';
        }, $content);
    }

    public function processGeneratedFilesPlaceholder(string $content): string
    {
        return preg_replace_callback(File::GENERATED_FILE_PATTERN, static function (array $matches): string {
            $prefix = $matches[1] ?? '';
            if ($prefix === '' || str_starts_with($prefix, '<img')) {
                return '<span class="generated-image-placeholder" aria-label="Image géneré">&#128247;</span>';
            }

            return $matches[0];
        }, $content);
    }
}
