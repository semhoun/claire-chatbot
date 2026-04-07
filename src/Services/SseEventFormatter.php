<?php

declare(strict_types=1);

namespace App\Services;

final readonly class SseEventFormatter
{
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
