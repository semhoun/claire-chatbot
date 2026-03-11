<?php

declare(strict_types=1);

env_required([
    'JWT_SECRET'
]);

return [
    // JWT-specific settings
    'jwt_secret' => _env('JWT_SECRET'),
    'jwt_algorithm' => _env('JWT_ALGORITHM', 'HS256'),

    // Cookie settings
    'name' => 'claire_chatbot',
    'lifetime' => 7200,
    'domain' => null,
    'secure' => false,
    'httponly' => false,
];
