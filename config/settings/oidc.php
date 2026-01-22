<?php

declare(strict_types=1);

return [
    'well_known_url' => env('OPENID_WELLKNOWN_URL'),
    'client_id' => env('OPENID_CLIENT_ID'),
    'client_secret' => env('OPENID_CLIENT_SECRET'),
    'redirect_uri_base' => env('OPENID_REDIRECT_URI_BASE'),
    'scopes' => ['openid', 'profile', 'email'],
    'default_user' => [
        'id' => 'default',
        'firstname' => 'Demo',
        'lastname' => null,
        'display' => 'Demo',
        'email' => null,
        'brain_avatar' => 'claire',
    ],
];
