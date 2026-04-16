<?php

declare(strict_types=1);

use App\Services\Env;
use App\Services\Settings;

Env::require([
    'BASE_URL',
]);

return [
    'version' => '1.4.3',
    'debug' => Env::get('DEBUG_MODE', false),
    'temporary_path' => Settings::getAppRoot() . '/var/tmp',
    'cache_dir' => Settings::getAppRoot() . '/var/cache',
    'base_url' => Env::get('BASE_URL'),
];
