<?php

declare(strict_types=1);

namespace App\Services\Twig;

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
        // Pattern to match "generated/xxx.png" in various contexts:
        // - Plain text: generated/abc.png
        // - Inside HTML tags: <p>generated/abc.png</p>
        // - With URL encoding: generated%2Fabc.png (unlikely but possible)
        $pattern = '/generated(?:\/|%2F)([a-zA-Z0-9_.-]+\.(?:png|jpg|jpeg|gif|webp))/i';

        return preg_replace_callback($pattern, static function (array $matches): string {
            $filename = $matches[1];

            return sprintf(
                '<img src="/files/serve/generated/%s" alt="Generated image" class="generated-image" loading="lazy">',
                htmlspecialchars($filename, ENT_QUOTES, 'UTF-8')
            );
        }, $content);
    }

    /**
     * Extract generated image paths from content.
     * Returns array of paths like "generated/xxx.png".
     */
    public function extractGeneratedImages(string $content): array
    {
        $pattern = '/generated\/([a-zA-Z0-9_.-]+\.(?:png|jpg|jpeg|gif|webp))/i';
        
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        return array_map(
            static fn (array $match): string => $match[0],
            $matches
        );
    }
}
