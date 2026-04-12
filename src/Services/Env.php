<?php

declare(strict_types=1);

namespace App\Services;

use Dotenv\Dotenv;
use Dotenv\Exception\ValidationException;
use RuntimeException;

final class Env
{
    private static bool $loaded = false;

    private static ?Dotenv $dotenv = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        self::boot();

        if ($key === '') {
            self::require($default);

            return null;
        }

        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => $value,
        };
    }

    /** @param array<int, string>|string $keys */
    public static function require(array|string $keys): void
    {
        self::boot();

        try {
            self::$dotenv?->required((array) $keys);
        } catch (ValidationException $validationException) {
            throw new RuntimeException(
                $validationException->getMessage(),
                (int) $validationException->getCode(),
                $validationException
            );
        }
    }

    private static function boot(): void
    {
        if (self::$loaded) {
            return;
        }

        $appRoot = Settings::getAppRoot();
        self::$dotenv = Dotenv::createUnsafeImmutable($appRoot);

        if (file_exists($appRoot . '/.env')) {
            self::$dotenv->load();
        }

        self::$loaded = true;
    }
}
