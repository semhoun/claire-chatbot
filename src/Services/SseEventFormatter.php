<?php

declare(strict_types=1);

namespace App\Services;

final readonly class SseEventFormatter
{
    /**
     * @param array<string, mixed> $payload
     */
    public function formatJsonEvent(array $payload, ?string $eventId = null, ?string $eventName = null): string
    {
        return $this->formatDataPayload(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $eventId,
            $eventName,
        );
    }

    public function keepalive(): string
    {
        return ": keepalive\n\n";
    }

    private function formatDataPayload(
        string $payload,
        ?string $eventId = null,
        ?string $eventName = null,
    ): string {
        return $this->formatOptionalField('id', $eventId)
            . $this->formatOptionalField('event', $eventName)
            . "data: {$payload}\n\n";
    }

    private function formatOptionalField(string $field, ?string $value): string
    {
        return $value !== null && $value !== ''
            ? $field . ': ' . $value . "\n"
            : '';
    }
}
