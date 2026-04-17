<?php

declare(strict_types=1);

use App\Services\Env;

Env::require([
    'REDIS_HOST',
]);

return [
    'host' => Env::get('REDIS_HOST'),
    'port' => (int) Env::get('REDIS_PORT', 6379),
    'database' => (int) Env::get('REDIS_DATABASE', 0),
    'password' => Env::get('REDIS_PASSWORD'),
    'timeout' => (float) Env::get('REDIS_TIMEOUT', 2.0),
    'readTimeout' => (float) Env::get('REDIS_READ_TIMEOUT', 5.0),
    'prefix' => (string) Env::get('REDIS_PREFIX', 'claire:'),
];
