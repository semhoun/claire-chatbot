<?php

declare(strict_types=1);

namespace App\Sse;

use App\Services\Settings;

final readonly class Config
{
    /** @var array<string, mixed> */
    private array $values;

    public function __construct(Settings $settings)
    {
        $values = $settings->get('sse');
        foreach (['duration', 'max_duration', 'check_interval', 'keepalive', 'http_timeout', 'max_connections',
            'max_http_requests', 'max_pending_events', 'max_client_buffer', 'max_global_buffer',
            'write_timeout', 'shutdown_timeout', 'max_redis_commands',
        ] as $key) {
            if (! is_int($values[$key] ?? null) || $values[$key] < 1) {
                throw new \InvalidArgumentException('Invalid SSE setting: ' . $key);
            }
        }
        if (! is_string($values['secret'] ?? null) || trim($values['secret']) === ''
            || preg_match('/[\r\n]/', $values['secret'])
            || ($values['backend'] ?? null) !== 'http://127.0.0.1:8082'
            || ! is_string($values['listen'] ?? null)
            || ! preg_match('/^127\.0\.0\.1:([0-9]{1,5})$/D', $values['listen'], $match)
            || (int) $match[1] < 1 || (int) $match[1] > 65535
            || $values['duration'] > $values['max_duration'] || $values['max_duration'] > 86400
            || $values['max_client_buffer'] > $values['max_global_buffer']) {
            throw new \InvalidArgumentException('Invalid SSE security or buffer configuration');
        }
        $this->values = $values;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key];
    }
}
