<?php

declare(strict_types=1);

namespace App\Services;

final readonly class GeneratedFileService
{
    public const string GENERATED_FILE_PATTERN = '/@@GENERATED@@([a-zA-Z0-9_\-@]+\.(?:png|jpg|jpeg|gif|webp|pdf))@@/i';

    public const string FOLDER_PREFIX = 'generated';

    public const string FOLDER_SEPARATOR = '@';

    private function __construct()
    {
    }

    public static function isPdf(string $fileId): bool
    {
        return str_ends_with(strtolower($fileId), '.pdf');
    }

    public static function isImage(string $fileId): bool
    {
        return ! self::isPdf($fileId);
    }
}
