<?php

declare(strict_types=1);

namespace App\Services;

final readonly class SseEventFormatter
{
    /**
     * Format for native EventSource (inspired by sse-driven-htmx)
     * Returns: data: {"html": {"elementId": "content"}}\n\n
     */
    public function formatHtmlUpdate(string $elementId, string $html): string
    {
        $payload = json_encode([
            'html' => [
                $elementId => $html,
            ],
        ], JSON_THROW_ON_ERROR);

        return "data: {$payload}\n\n";
    }

    /**
     * Format for native EventSource with JS execution
     * Returns: data: {"js": {"exec": "code"}}\n\n
     */
    public function formatJsExec(string $code): string
    {
        $payload = json_encode([
            'js' => [
                'exec' => $code,
            ],
        ], JSON_THROW_ON_ERROR);

        return "data: {$payload}\n\n";
    }

    /**
     * Format for HTMX SSE extension with named events
     * Returns: event: name\ndata: <html>\n\n
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
     * Legacy format with named events (for HTMX SSE extension)
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
}
