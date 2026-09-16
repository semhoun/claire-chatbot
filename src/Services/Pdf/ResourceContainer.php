<?php

declare(strict_types=1);

namespace App\Services\Pdf;

use InvalidArgumentException;
use Mpdf\Container\ContainerInterface;

final readonly class ResourceContainer implements ContainerInterface
{
    public function __construct(private RestrictedAssetFetcher $restrictedAssetFetcher)
    {
    }

    public function has($id): bool
    {
        return $id === 'assetFetcher';
    }

    public function get($id): RestrictedAssetFetcher
    {
        if (! $this->has($id)) {
            throw new InvalidArgumentException('Unknown PDF service.');
        }

        return $this->restrictedAssetFetcher;
    }
}
