<?php

declare(strict_types=1);

use App\Services\Env;
use App\Services\Settings;

// Optional environment variables - ComfyUI integration is optional
return [
    'enabled' => (bool) Env::get('COMFYUI_ENABLED', false),
    'url' => Env::get('COMFYUI_URL', 'http://localhost:8188'),
    'timeout' => (int) Env::get('COMFYUI_TIMEOUT', 300),
    'workflows_path' => Settings::getAddonsPath() . '/comfyui',
    'default_workflow' => Env::get('COMFYUI_DEFAULT_WORKFLOW'),
];
