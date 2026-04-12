<?php

declare(strict_types=1);

use App\Services\Env;
use App\Services\Settings;

return [
    'version' => '1.4.2',
    'debug' => Env::get('DEBUG_MODE', false),
    'temporary_path' => Settings::getAppRoot() . '/var/tmp',
    'cache_dir' => Settings::getAppRoot() . '/var/cache',
];
