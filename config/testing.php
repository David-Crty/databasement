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

        'mssql' => [
            'host' => env('TEST_MSSQL_HOST', 'mssql'),
            'port' => env('TEST_MSSQL_PORT', 1433),
            'username' => env('TEST_MSSQL_USERNAME', 'sa'),
            'password' => env('TEST_MSSQL_PASSWORD', 'Admin123!'),
            'database' => env('TEST_MSSQL_DATABASE', 'databasement_test'),
        ],
    ],
];
