<?php

declare(strict_types=1);

return [
    'well_known_url' => getenv('OPENID_WELLKNOWN_URL', true),
    'client_id' => getenv('OPENID_CLIENT_ID', true),
    'client_secret' => getenv('OPENID_CLIENT_SECRET', true),
    'redirect_uri_base' => getenv('OPENID_REDIRECT_URI_BASE', true),
    'scopes' => ['openid', 'profile', 'email'],
];
