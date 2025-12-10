<?php

declare(strict_types=1);

use App\Services\Settings;

return [
    'upload' => [
        // String used directly in input[type=file] accept="..."
        'acceptedExt' => 'image/*,.pdf,.doc,.docx,.png,.jpg,.jpeg,.json,.txt,.csv',
    ],
    'rawMimeTypes' => [
        'application/x-csh',
        'text/css',
        'text/csv',
        'text/html',
        'text/calendar',
        'application/javascript',
        'application/json',
        'application/x-sh',
        'image/svg+xml',
        'application/typescript',
        'application/xhtml+xml',
        'application/xml',
    ],
    'fileSystem' => [
        'type' => 'local',
        'path' => Settings::getAppRoot() . '/var/filer',
    ],
];
