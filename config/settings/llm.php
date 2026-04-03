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
        'contextWindow' => 10000,// (int) env('OPENAPI_CONTEXT_WINDOW', 50000),
    ],
    'shortMemory' => [
        'messageToKeep' => 5,
        'maxTokens' => 3000,
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
        'path' => Settings::getAppRoot() . '/addons/agents',
    ],
    'defaultBrain' => 'claire',
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
