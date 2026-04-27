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

    // Token lifetime in seconds (default: 2 hours)
    'lifetime' => 7200,

    'defaultParams' => [
        'brain_avatar' => 'claire',
        'layout_mode' => 'full',
        ComfyUIWorkflowRegistry::SESSION_KEY => null,
    ],
];
