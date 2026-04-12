<?php

declare(strict_types=1);

use App\Services\Env;
use App\Services\Settings;

return [
    // Driver: sqlite, mysql, pgsql
    'driver' => Env::get('DATABASE_KIND'),

    // Used for mysql, pgsql
    'host' => Env::get('DATABASE_HOST'),
    'port' => Env::get('DATABASE_PORT'),
    'dbname' => Env::get('DATABASE_NAME'),
    'user' => Env::get('DATABASE_USER'),
    'password' => Env::get('DATABASE_PASSWORD'),

    // Used only for sqlite
    'path' => Settings::getDataPath() . '/database.sqlite',

    'doctrine' => [
        'entity_path' => [Settings::getAppRoot() . '/src/Entity'],
        'migrations' => [
            'table_storage' => [
                'table_name' => 'db_version',
                'version_column_name' => 'version',
                'version_column_length' => 64,
                'executed_at_column_name' => 'executed_at',
                'execution_time_column_name' => 'execution_time',
            ],
            'migrations_paths' => [
                'Migrations' => Settings::getAppRoot() . '/migrations',
            ],
            'all_or_nothing' => true,
            'transactional' => true,
            'check_database_platform' => true,
            'organize_migrations' => 'none',
            'connection' => null,
            'em' => null,
            'custom_template' => Settings::getAppRoot()
                . '/migrations/doctrine_migrations_class.php.tpl',
        ],
    ],
];
