<?php

declare(strict_types=1);

namespace App\Services\Twig;

use App\Services\GeneratedFileService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class GeneratedFileExtension extends AbstractExtension
{
    private const string IMAGE_TAG_TEMPLATE = <<<'HTML'
<img src="/files/serve/%s" alt="Generated image" class="generated-image">
HTML;

    private const string PDF_TAG_TEMPLATE = <<<'HTML'
<a href="/files/serve/%s" class="generated-pdf" target="_blank" download>&#128196; PDF</a>
HTML;

    private const string PLACEHOLDER_TEMPLATE = <<<'HTML'
<span class="generated-image-placeholder" data-generated-image="%s" role="img" aria-label="Image generee">&#128247;</span>
HTML;

    private const string PDF_PLACEHOLDER_TEMPLATE = <<<'HTML'
<span class="generated-pdf-placeholder" data-generated-pdf="%s" role="link" aria-label="PDF genere">&#128196;</span>
HTML;

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

    /**
     * @return array<TwigFunction>
     */
    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('extract_generated_files', $this->extractGeneratedFiles(...)),
        ];
    }

    public function processGeneratedFiles(string $content): string
    {
        return preg_replace_callback(GeneratedFileService::GENERATED_FILE_PATTERN, static function (array $matches): string {
            $fileId = $matches[1];

            if (GeneratedFileService::isPdf($fileId)) {
                return sprintf(
                    self::PDF_TAG_TEMPLATE,
                    htmlspecialchars($fileId, ENT_QUOTES, 'UTF-8')
                );
            }

            return sprintf(
                self::IMAGE_TAG_TEMPLATE,
                htmlspecialchars($fileId, ENT_QUOTES, 'UTF-8')
            );
        }, $content);
    }

    public function processGeneratedFilesPlaceholder(string $content): string
    {
        return preg_replace_callback(GeneratedFileService::GENERATED_FILE_PATTERN, static function (array $matches): string {
            $fileId = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');

            if (GeneratedFileService::isPdf($matches[1])) {
                return sprintf(
                    self::PDF_PLACEHOLDER_TEMPLATE,
                    $fileId
                );
            }

            return sprintf(
                self::PLACEHOLDER_TEMPLATE,
                $fileId
            );
        }, $content);
    }

    /**
     * @return array<int, string>
     */
    public function extractGeneratedFiles(string $content): array
    {
        $matched = preg_match_all(
            GeneratedFileService::GENERATED_FILE_PATTERN,
            $content,
            $matches,
            PREG_SET_ORDER
        );

        if ($matched === false) {
            return [];
        }

        return array_map(
            static fn (array $match): string => $match[0],
            $matches
        );
    }
}
