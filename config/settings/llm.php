<?php

declare(strict_types=1);

use App\Services\Env;
use App\Services\Settings;

// Mandatory environment variables
Env::require([
    'OPENAPI_URL',
    'OPENAPI_MODEL',
]);

return [
    'openai' => [
        'key' => Env::get('OPENAPI_KEY'),
        'baseUri' => Env::get('OPENAPI_URL'),
        'model' => Env::get('OPENAPI_MODEL'),
        'modelSummary' => Env::get('OPENAPI_MODEL_SUMMARY') ?? Env::get('OPENAPI_MODEL'),
        'modelEmbed' => Env::get('OPENAPI_MODEL_EMBED'),
        'contextWindow' => (int) Env::get('OPENAPI_CONTEXT_WINDOW', 50000),
    ],
    'shortMemory' => [
        'messageToKeep' => 3,
        'maxTokens' => (int) Env::get('OPENAPI_CONTEXT_WINDOW', 50000) / 2,
    ],
    'summary' => [
        'minMessages' => 2,
        'maxMessages' => 8,
    ],
    'workflow' => [
        'timeout' => (int) Env::get('OPENAPI_WORKFLOW_TIMEOUT', 300),
    ],
    'tools' => [
        'searchXngUrl' => Env::get('SEARXNG_URL'),
    ],
    'rag' => [
        'type' => 'file', // Could be 'file'

        // Used only for file
        'path' => Settings::getDataPath() . '/rag',
    ],
    // Liste des assistants disponibles (slug => FQCN)
    'brains' => [
        'claire' => App\Brain\Claire::class,
        'einstein' => App\Brain\Einstein::class,
    ],
    'yamlBrains' => [
        'path' => Settings::getAddonsPath() . '/agents',
    ],
    'rawMimeTypes' => [
        'application/x-csh',
        'text/css',
        'text/csv',
        'text/html',
        'text/calendar',
        'application/javascript',
        'application/json',
        'application/jsonl',
        'application/x-sh',
        'image/svg+xml',
        'application/typescript',
        'application/xhtml+xml',
        'application/xml',
    ],
    'httpClient' => [
        'timeout' => (float) Env::get('OPENAPI_REQUEST_TIMEOUT', 180.0),
        'connectTimeout' => 10.0,
    ],
];
