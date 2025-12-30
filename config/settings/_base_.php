<?php

declare(strict_types=1);

use App\Services\Settings;

return [
    'version' => '1.0.0',
    'debug' => getenv('DEBUG_MODE', true) === 'true',
    'temporary_path' => Settings::getAppRoot() . '/var/tmp',
    'cache_dir' => Settings::getAppRoot() . '/var/cache',
];
