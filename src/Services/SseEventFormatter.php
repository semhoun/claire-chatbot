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

    /**
     * Format for HTMX SSE extension with named events
     * Returns: event: name\ndata: <html>\n\n.
     */
    public function formatNamedEvent(string $event, string $data): string
    {
        $lines = preg_split('/\R/', $data);

        if ($lines === false) {
            $lines = [''];
        }

        $payload = sprintf('event: %s%s', $event, PHP_EOL);

        foreach ($lines as $line) {
            $payload .= 'data: ' . $line . "\n";
        }

        return $payload . "\n";
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
