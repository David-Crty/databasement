<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Test Database Connections
    |--------------------------------------------------------------------------
    |
    | Configuration for external database connections used in automated tests.
    | These databases are used for integration tests that require real
    | MySQL and PostgreSQL connections (e.g., backup/restore tests).
    |
    */

    'databases' => [
        'mysql' => [
            'host' => env('TEST_MYSQL_HOST'),
            'port' => env('TEST_MYSQL_PORT'),
            'username' => env('TEST_MYSQL_USERNAME'),
            'password' => env('TEST_MYSQL_PASSWORD'),
            'database' => env('TEST_MYSQL_DATABASE'),
        ],

        'postgres' => [
            'host' => env('TEST_POSTGRES_HOST'),
            'port' => env('TEST_POSTGRES_PORT'),
            'username' => env('TEST_POSTGRES_USERNAME'),
            'password' => env('TEST_POSTGRES_PASSWORD'),
            'database' => env('TEST_POSTGRES_DATABASE'),
        ],
    ],
];
