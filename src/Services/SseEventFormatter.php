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
     * Legacy format with named events (for HTMX SSE extension)
     * @deprecated Use formatHtmlUpdate instead
     */
    public function format(string $event, string $data): string
    {
        $lines = preg_split('/\R/', $data) ?: [''];
        $payload = "event: {$event}\n";

        foreach ($lines as $line) {
            $payload .= 'data: ' . $line . "\n";
        }

        return $payload . "\n";
    }

    public function keepalive(): string
    {
        return ": keepalive\n\n";
    }
}
