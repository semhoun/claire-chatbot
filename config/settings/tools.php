<?php

declare(strict_types=1);

use App\Services\Env;
use App\Services\Settings;

return [
    'searXNG' => [
        'enabled' => Env::get('SEARXNG_URL') !== null,
        'url' => Env::get('SEARXNG_URL'),
    ],
    'comfyui' => [
        'enabled' => (bool) Env::get('COMFYUI_ENABLED', false),
        'url' => Env::get('COMFYUI_URL', 'http://localhost:8188'),
        'timeout' => (int) Env::get('COMFYUI_TIMEOUT', 300),
        'workflows_path' => Settings::getAddonsPath() . '/comfyui',
        'default_workflow' => Env::get('COMFYUI_DEFAULT_WORKFLOW'),
    ],
    'pdf' => [
        'enabled' => (bool) Env::get('PDF_ENABLED', true),
        'defaultFormat' => Env::get('PDF_DEFAULT_FORMAT', 'html'),
        'defaultPageSize' => Env::get('PDF_DEFAULT_PAGE_SIZE', 'A4'),
        'tempDir' => Env::get('PDF_TEMP_DIR', Settings::getAppRoot() . '/var/tmp'),
    ],
];
