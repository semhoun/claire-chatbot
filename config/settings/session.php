<?php

declare(strict_types=1);

env_required([
    'SESSION_JWT_SECRET',
]);

return [
    // JWT-specific settings
    'jwt_secret' => _env('SESSION_JWT_SECRET'),
    'jwt_algorithm' => _env('SESSION_JWT_ALGORITHM', 'HS256'),

    // Cookie settings
    'name' => 'claire_chatbot',
    'lifetime' => 7200,
    'domain' => null,
    'secure' => false,
    'httponly' => false,
];
