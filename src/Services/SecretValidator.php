<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class SecretValidator
{
    /** @param list<string> $disallowedValues */
    public static function validate(
        string $key,
        string|false $value,
        int $minimumLength,
        array $disallowedValues,
    ): string {
        if ($value === false || trim($value) === '') {
            throw new RuntimeException(
                sprintf('Missing or empty secret environment variable: %s', $key)
            );
        }

        if (strlen($value) < $minimumLength) {
            throw new RuntimeException(
                sprintf(
                    'Secret environment variable %s must contain at least %d bytes',
                    $key,
                    $minimumLength,
                )
            );
        }

        if (in_array($value, $disallowedValues, true)) {
            throw new RuntimeException(
                sprintf('Secret environment variable %s uses a forbidden value', $key)
            );
        }

        return $value;
    }
}
