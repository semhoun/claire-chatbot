<?php

declare(strict_types=1);

use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Env;

Env::require([
    'SESSION_JWT_SECRET',
]);

return [
    // JWT signing (REQUIRED)
    'jwt' => [
        'secret' => Env::get('SESSION_JWT_SECRET'),
        'algorithm' => Env::get('SESSION_JWT_ALGORITHM', 'HS256'),
    ],

    // Token lifetime in seconds (default: 15 min)
    'lifetime' => (int) Env::get('SESSION_LIFETIME', 900),
    // Refresh margins in seconds
    'refresh_before_expire' => (int) Env::get('SESSION_REFRESH_BEFORE_EXPIRE', 120),
    'refresh_min_interval' => (int) Env::get('SESSION_REFRESH_MIN_INTERVAL', 30),

    'defaultParams' => [
        'brain_avatar' => 'claire',
        'layout_mode' => 'full',
        ComfyUIWorkflowRegistry::SESSION_KEY => null,
    ],
];
