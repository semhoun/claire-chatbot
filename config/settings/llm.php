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
    'longTermMemory' => [
        'maxCharacters' => (int) Env::get('LONG_TERM_MEMORY_MAX_CHARACTERS', 4000),
        'updateEveryUserMessages' => (int) Env::get('LONG_TERM_MEMORY_UPDATE_EVERY_USER_MESSAGES', 5),
        'rebuildBatchSize' => (int) Env::get('LONG_TERM_MEMORY_REBUILD_BATCH_SIZE', 20),
    ],
    'summary' => [
        'minMessages' => (int) Env::get('SUMMARY_MIN_MESSAGES', 2),
        'maxMessages' => (int) Env::get('SUMMARY_MAX_MESSAGES', 8),
    ],
    'rag' => [
        'path' => Settings::getDataPath() . '/rag',
        'chunkSize' => (int) Env::get('RAG_CHUNK_SIZE', 1000),
        'topK' => (int) Env::get('RAG_TOP_K', 4),
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
