<?php

declare(strict_types=1);

use App\Services\Env;
use App\Services\Settings;

return [
    'upload' => [
        // String used directly in input[type=file] accept="..."
        'acceptedExt' => 'image/*,.pdf,.doc,.docx,.png,.jpg,.jpeg,.json,.txt,.csv',
    ],
    'fileSystem' => [
        'type' => 'local',
        'path' => Env::get('FILES_PATH', Settings::getDataPath() . '/filer'),
    ],
];
