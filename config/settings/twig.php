<?php

declare(strict_types=1);

use App\Services\Settings;

$debug = getenv('DEBUG_MODE', true) === 'true';

return [
    'template_path' => Settings::getAppRoot() . '/tmpl',
    'config' => [
        'cache' => Settings::getAppRoot() . '/var/cache/twig',
        'debug' => true,
        'auto_reload' => $debug,
    ],
];
