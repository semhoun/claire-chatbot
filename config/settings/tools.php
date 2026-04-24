<?php

declare(strict_types=1);

use App\Services\Env;

return [
    'searXNG' => [
        'enabled' => Env::get('SEARXNG_URL') !== null,
        'url' => Env::get('SEARXNG_URL'),
    ],
];
