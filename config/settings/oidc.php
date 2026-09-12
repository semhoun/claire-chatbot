<?php

declare(strict_types=1);

use App\Services\Env;

Env::require([
    'OPENID_WELLKNOWN_URL',
    'OPENID_CLIENT_ID',
]);

return [
    'well_known_url' => Env::get('OPENID_WELLKNOWN_URL'),
    'client_id' => Env::get('OPENID_CLIENT_ID'),
    'client_secret' => Env::get('OPENID_CLIENT_SECRET', ''),
    'access_token_client_ids' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) Env::get('OPENID_ACCESS_TOKEN_CLIENT_IDS', Env::get('OPENID_CLIENT_ID'))),
    ), static fn (string $id): bool => $id !== '')),
    'scopes' => ['openid', 'profile', 'email'],
];
