<?php

declare(strict_types=1);

return [
    'cors' => [
        'allowed_origins' => explode(',', (string) (\App\Services\Env::get('ALLOWED_CORS_ORIGINS', '*'))),
    ],
    'public_routes' => [
        '/health',
        '/embed',
        '/logout',
        '/auth',
        '/telegram',
    ],
];
