<?php

declare(strict_types=1);

use App\Services\Settings;

// Mandatory environment variables
env_required([
    'OPENAPI_URL',
    'OPENAPI_MODEL',
]);

return [
    'openai' => [
        'key' => env('OPENAPI_KEY'),
        'baseUri' => env('OPENAPI_URL'),
        'model' => env('OPENAPI_MODEL'),
        'modelSummary' => env('OPENAPI_MODEL_SUMMARY') ?? env('OPENAPI_MODEL'),
        'modelEmbed' => env('OPENAPI_MODEL_EMBED'),
        'contextWindow' => (int) env('OPENAPI_CONTEXT_WINDOW', 50000),
    ],
    'shortMemory' => [
        'messageToKeep' => 3,
        'maxTokens' => (int) env('OPENAPI_CONTEXT_WINDOW', 50000) / 2,
    ],
    'summary' => [
        'minMessages' => 2,
        'maxMessages' => 8,
    ],
    'workflow' => [
        'timeout' => 600,
    ],
    'tools' => [
        'searchXngUrl' => env('SEARXNG_URL'),
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
        'timeout' => 300.0,
        'connectTimeout' => 10.0,
    ],
];
