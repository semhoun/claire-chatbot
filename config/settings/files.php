<?php

declare(strict_types=1);

use App\Services\Env;
use App\Services\Settings;

return [
    'upload' => [
        // String used directly in input[type=file] accept="..."
        'acceptedExt' => 'image/*,.pdf,.doc,.docx,.png,.jpg,.jpeg,.json,.txt,.csv,.md',
        'path' => 'uploads',
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'application/pdf',
            'text/plain',
            'text/markdown',
            'text/csv',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/rtf',
            'application/zip',
        ],
        'forbidden_extensions' => [
            'php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'phar',
            'sh', 'bash', 'exe', 'bin', 'js', 'vbs', 'pl', 'py',
        ],
    ],
    'fileSystem' => [
        'type' => 'local',
        'path' => Env::get('FILES_PATH', Settings::getDataPath() . '/filer'),
    ],
];
