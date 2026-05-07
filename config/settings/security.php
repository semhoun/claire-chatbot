<?php

declare(strict_types=1);

use App\Services\Env;

return [
    'cors' => [
        'allowed_origins' => explode(',', (string) (Env::get('ALLOWED_CORS_ORIGINS', '*'))),
    ],
    'public_routes' => [
        '/health',
        '/embed',
        '/logout',
        '/auth',
        '/telegram',
    ],
];
