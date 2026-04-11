<?php

declare(strict_types=1);

namespace App\Services\Twig;

use App\Services\ComfyUIService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class GeneratedImageExtension extends AbstractExtension
{
    /**
     * @return array<TwigFilter>
     */
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('process_generated_images', $this->processGeneratedImages(...), ['is_safe' => ['html']]),
            new TwigFilter('process_generated_images_placeholder', $this->processGeneratedImagesPlaceholder(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @return array<TwigFunction>
     */
    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('extract_generated_images', $this->extractGeneratedImages(...)),
        ];
    }

    /**
     * Convert generated/xxx.png paths to <img> tags.
     * Works with both plain text and HTML content (after markdown_to_html).
     */
    public function processGeneratedImages(string $content): string
    {
        return preg_replace_callback(ComfyUIService::IMAGE_PATTERN, static function (array $matches): string {
            $imgId = $matches[1];

            return sprintf(
                '<img src="/files/img_serve/%s" alt="Generated image" class="generated-image">',
                htmlspecialchars($imgId, ENT_QUOTES, 'UTF-8')
            );
        }, $content);
    }

    /**
     * Convert generated/xxx.png paths to a lightweight placeholder during streaming.
     */
    public function processGeneratedImagesPlaceholder(string $content): string
    {
        return preg_replace_callback(ComfyUIService::IMAGE_PATTERN, static function (array $matches): string {
            $imgId = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');

            return sprintf(
                '<span class="generated-image-placeholder" data-generated-image="%s" role="img" aria-label="Image generee">&#128247;</span>',
                $imgId
            );
        }, $content);
    }

    /**
     * Extract generated image paths from content.
     * Returns array of paths like "generated/xxx.png".
     */
    public function extractGeneratedImages(string $content): array
    {
        if (preg_match_all(ComfyUIService::IMAGE_PATTERN, $content, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        return array_map(
            static fn (array $match): string => $match[0],
            $matches
        );
    }
}
