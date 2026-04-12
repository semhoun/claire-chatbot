<?php

declare(strict_types=1);

use App\Services\Env;
use App\Services\Settings;

return [
    'template_path' => Settings::getAppRoot() . '/tmpl',
    'config' => [
        'cache' => Settings::getAppRoot() . '/var/cache/twig',
        'debug' => true,
        'auto_reload' => Env::get('DEBUG_MODE', false),
    ],
];
