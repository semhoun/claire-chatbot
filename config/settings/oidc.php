<?php

declare(strict_types=1);

use App\Services\Env;

return [
    'well_known_url' => Env::get('OPENID_WELLKNOWN_URL'),
    'client_id' => Env::get('OPENID_CLIENT_ID'),
    'client_secret' => Env::get('OPENID_CLIENT_SECRET'),
    'scopes' => ['openid', 'profile', 'email'],
    'default_user' => [
        'id' => 'default',
        'data' => [
            'firstname' => 'Demo',
            'lastname' => null,
            'display' => 'Demo',
            'email' => null,
            'brain_avatar' => 'claire',
        ],
    ],
];
