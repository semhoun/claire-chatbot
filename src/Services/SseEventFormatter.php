<?php

declare(strict_types=1);

namespace App\Services;

final readonly class SseEventFormatter
{
    /**
     * Format for native EventSource (inspired by sse-driven-htmx)
     * Returns: data: {"html": {"elementId": "content"}}\n\n.
     */
    public function formatHtmlUpdate(string $elementId, string $html, ?string $eventId = null): string
    {
        $payload = json_encode([
            'html' => [
                $elementId => $html,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->formatDataPayload($payload, $eventId);
    }

    /**
     * Format for native EventSource with JS execution
     * Returns: data: {"js": {"exec": "code"}}\n\n.
     */
    public function formatJsExec(string $code): string
    {
        $payload = json_encode([
            'js' => [
                'exec' => $code,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return "data: {$payload}\n\n";
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function formatJsonEvent(array $payload, ?string $eventId = null): string
    {
        return $this->formatDataPayload(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $eventId,
        );
    }

    /**
     * Format for HTMX SSE extension with named events
     * Returns: event: name\ndata: <html>\n\n.
     */
    public function formatNamedEvent(string $event, string $data): string
    {
        $lines = preg_split('/\R/', $data) ?: [''];
        $payload = sprintf('event: %s%s', $event, PHP_EOL);

        foreach ($lines as $line) {
            $payload .= 'data: ' . $line . "\n";
        }

        return $payload . "\n";
    }

    /**
     * Legacy format with named events (for HTMX SSE extension).
     */
    #[\Deprecated(message: 'Use formatNamedEvent instead')]
    public function format(string $event, string $data): string
    {
        return $this->formatNamedEvent($event, $data);
    }

    public function keepalive(): string
    {
        return ": keepalive\n\n";
    }

    private function formatDataPayload(string $payload, ?string $eventId = null): string
    {
        $frame = '';
        if ($eventId !== null && $eventId !== '') {
            $frame .= 'id: ' . $eventId . "\n";
        }

        return $frame . "data: {$payload}\n\n";
    }
}
