<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class Env
{
    public static function get(string $key, mixed $default = null): mixed
    {
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
        $missing = [];

        foreach ((array) $keys as $key) {
            if (getenv($key) === false) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'Missing environment variables: ' . implode(', ', $missing)
            );
        }
    }
}
