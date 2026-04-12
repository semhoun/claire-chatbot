<?php

declare(strict_types=1);

use App\Services\Env;

return [
    'well_known_url' => Env::get('OPENID_WELLKNOWN_URL'),
    'client_id' => Env::get('OPENID_CLIENT_ID'),
    'client_secret' => Env::get('OPENID_CLIENT_SECRET'),
    'redirect_uri_base' => Env::get('OPENID_REDIRECT_URI_BASE'),
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
