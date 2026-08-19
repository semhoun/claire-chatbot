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

    /** @param list<string> $disallowedValues */
    public static function getSecret(
        string $key,
        int $minimumLength = 32,
        array $disallowedValues = [],
    ): string {
        return SecretValidator::validate(
            $key,
            getenv($key),
            $minimumLength,
            $disallowedValues,
        );
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
