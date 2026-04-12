<?php

declare(strict_types=1);

use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Env;

Env::require([
    'SESSION_JWT_SECRET',
]);

return [
    // JWT-specific settings
    'jwt_secret' => Env::get('SESSION_JWT_SECRET'),
    'jwt_algorithm' => Env::get('SESSION_JWT_ALGORITHM', 'HS256'),

    // Cookie settings
    'name' => 'claire_chatbot',
    'lifetime' => 7200,
    'domain' => null,
    'secure' => false,
    'httponly' => false,

    'defaultParams' => [
        'brain_avatar' => 'claire',
        'layout_mode' => 'full',
        ComfyUIWorkflowRegistry::SESSION_KEY => null,
    ],
];
