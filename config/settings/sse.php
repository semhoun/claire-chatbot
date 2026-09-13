<?php

declare(strict_types=1);

use App\Services\Env;

$integer = static function (string $name, int $default): int {
    $value = Env::get($name, $default);
    if ((! is_int($value) && ! is_string($value))
        || filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException('Invalid SSE integer setting: ' . $name);
    }

    return (int) $value;
};

return [
    'secret' => (string) Env::get('SSE_INTERNAL_SECRET', ''),
    'listen' => (string) Env::get('SSE_LISTEN', '127.0.0.1:8081'),
    'backend' => (string) Env::get('SSE_BACKEND', 'http://127.0.0.1:8082'),
    'duration' => $integer('SSE_DURATION', 1800),
    'max_duration' => 86400,
    'check_interval' => $integer('SSE_CHECK_INTERVAL', 15),
    'keepalive' => $integer('SSE_KEEPALIVE', 15),
    'http_timeout' => $integer('SSE_HTTP_TIMEOUT', 10),
    'max_connections' => $integer('SSE_MAX_CONNECTIONS', 1000),
    'max_http_requests' => $integer('SSE_MAX_HTTP_REQUESTS', 16),
    'max_pending_events' => $integer('SSE_MAX_PENDING_EVENTS', 256),
    'max_client_buffer' => $integer('SSE_MAX_CLIENT_BUFFER', 16777216),
    'max_global_buffer' => $integer('SSE_MAX_GLOBAL_BUFFER', 134217728),
    'write_timeout' => $integer('SSE_WRITE_TIMEOUT', 15),
    'shutdown_timeout' => $integer('SSE_SHUTDOWN_TIMEOUT', 5),
    'max_redis_commands' => $integer('SSE_MAX_REDIS_COMMANDS', 64),
];
