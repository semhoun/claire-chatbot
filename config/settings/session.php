<?php

declare(strict_types=1);

use App\Brain\LongTermMemory;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Env;

$jwtSecret = Env::getSecret(
    'SESSION_JWT_SECRET',
    disallowedValues: [
        'changeme00changeme00changeme00changeme00changeme00changeme00',
    ],
);

return [
    // JWT signing (REQUIRED)
    'jwt' => [
        'secret' => $jwtSecret,
    ],

    // Token lifetime in seconds (default: 15 min)
    'lifetime' => (int) Env::get('SESSION_LIFETIME', 900),
    // Refresh margins in seconds
    'refresh_before_expire' => (int) Env::get('SESSION_REFRESH_BEFORE_EXPIRE', 120),
    'refresh_min_interval' => (int) Env::get('SESSION_REFRESH_MIN_INTERVAL', 30),

    'defaultParams' => [
        'brain_avatar' => 'claire',
        'layout_mode' => 'full',
        LongTermMemory::SESSION_KEY => false,
        ComfyUIWorkflowRegistry::SESSION_KEY => null,
    ],
];
