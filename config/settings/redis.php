<?php

declare(strict_types=1);

return [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => (int) env('REDIS_PORT', 6379),
    'database' => (int) env('REDIS_DATABASE', 0),
    'password' => env('REDIS_PASSWORD'),
    'timeout' => (float) env('REDIS_TIMEOUT', 2.0),
    'prefix' => (string) env('REDIS_PREFIX', 'claire:'),
];
