<?php

declare(strict_types=1);

namespace App\Services\Queue;

use JsonException;
use RuntimeException;

final class QueueSerializer
{
    /**
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Unable to encode queue payload', 0, $jsonException);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Unable to decode queue payload', 0, $jsonException);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Queue payload must decode to an array');
        }

        return $decoded;
    }
}
