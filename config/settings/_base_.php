<?php

declare(strict_types=1);

use App\Services\Env;
use App\Services\Settings;

Env::require([
    'BASE_URL',
]);

return [
    'version' => Env::get('CLAIRE_APP_VERSION', 'wip'),
    'debug' => Env::get('DEBUG_MODE', false),
    'cache_dir' => Settings::getAppRoot() . '/var/cache',
    'base_url' => Env::get('BASE_URL'),
];
