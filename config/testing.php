<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Test Database Connections
    |--------------------------------------------------------------------------
    |
    | Configuration for external database connections used in automated tests.
    | These databases are used for integration tests that require real
    | database connections (e.g., backup/restore tests).
    |
    | Default values match the Docker Compose services configuration.
    |
    */

    'databases' => [
        'mysql' => [
            'host' => env('TEST_MYSQL_HOST', 'mysql'),
            'port' => env('TEST_MYSQL_PORT', 3306),
            'username' => env('TEST_MYSQL_USERNAME', 'root'),
            'password' => env('TEST_MYSQL_PASSWORD', 'root'),
            'database' => env('TEST_MYSQL_DATABASE', 'databasement_test'),
        ],

        'postgres' => [
            'host' => env('TEST_POSTGRES_HOST', 'postgres'),
            'port' => env('TEST_POSTGRES_PORT', 5432),
            'username' => env('TEST_POSTGRES_USERNAME', 'root'),
            'password' => env('TEST_POSTGRES_PASSWORD', 'root'),
            'database' => env('TEST_POSTGRES_DATABASE', 'databasement_test'),
        ],

        'redis' => [
            'host' => env('TEST_REDIS_HOST', 'redis'),
            'port' => env('TEST_REDIS_PORT', 6379),
            'password' => env('TEST_REDIS_PASSWORD', null),
        ],
    ],
];
