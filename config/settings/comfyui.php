<?php

declare(strict_types=1);

use App\Services\Settings;

// Optional environment variables - ComfyUI integration is optional
return [
    'url' => env('COMFYUI_URL', 'http://localhost:8188'),
    'enabled' => (bool) env('COMFYUI_ENABLED', false),
    'timeout' => (int) env('COMFYUI_TIMEOUT', 300),
    'workflows_path' => Settings::getAppRoot() . '/addons/comfyui',
    'default_workflow' => env('COMFYUI_DEFAULT_WORKFLOW'),
];
