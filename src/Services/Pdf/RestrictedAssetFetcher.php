<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use Mpdf\AssetFetcher;
use RuntimeException;

final class RestrictedAssetFetcher extends AssetFetcher
{
    /** @param list<string> $paths Exact raster paths created for this render. */
    public function __construct(private readonly array $paths)
    {
        // Never initialize or delegate to the network-capable parent implementation.
    }

    public function fetchDataFromPath($path, $originalSrc = null): string
    {
        if (! in_array($path, $this->paths, true)
            || ($originalSrc !== null && ! in_array($originalSrc, $this->paths, true))) {
            throw new RuntimeException(
                'PDF resource denied. Use only images from generate_image markers; '
                . 'external and local resources are forbidden.',
            );
        }

        $data = @file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException('Cannot read prepared PDF image.');
        }

        return $data;
    }

    public function fetchLocalContent($path, $originalSrc): string
    {
        return $this->fetchDataFromPath($path, $originalSrc);
    }

    public function fetchRemoteContent($path): string
    {
        throw new RuntimeException('PDF external resources are forbidden.');
    }
}
