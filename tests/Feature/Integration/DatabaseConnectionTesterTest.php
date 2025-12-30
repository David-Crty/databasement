<?php

/**
 * Integration tests for DatabaseConnectionTester with real databases.
 *
 * These tests require MySQL and PostgreSQL containers to be running.
 * Run with: php artisan test --group=integration
 */

use App\Services\DatabaseConnectionTester;
use Tests\Support\IntegrationTestHelpers;

uses()->group('integration');

test('connection succeeds', function (string $databaseType) {
    $config = IntegrationTestHelpers::getDatabaseConfig($databaseType);
    if ($databaseType === 'sqlite') {
        IntegrationTestHelpers::createTestSqliteDatabase($config['host']);
    }

    $result = DatabaseConnectionTester::test([
        'database_type' => $databaseType,
        'host' => $config['host'],
        'port' => $config['port'],
        'username' => $config['username'],
        'password' => $config['password'],
        'database_name' => $config['database'],
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful');

    if ($databaseType === 'sqlite') {
        unlink($config['host']);
    }
})->with(['mysql', 'postgres', 'sqlite']);

test('connection fails with invalid credentials', function (string $databaseType) {
    $config = IntegrationTestHelpers::getDatabaseConfig($databaseType);

    $result = DatabaseConnectionTester::test([
        'database_type' => $databaseType,
        'host' => $config['host'],
        'port' => $config['port'],
        'username' => 'invalid_user',
        'password' => 'invalid_password',
        'database_name' => $config['database'],
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->not->toBeEmpty();
})->with(['mysql', 'postgres']);

test('connection fails with unreachable host', function (string $databaseType, int $port) {
    $result = DatabaseConnectionTester::test([
        'database_type' => $databaseType,
        'host' => '127.0.0.1',
        'port' => $port, // Wrong port - nothing listening here
        'username' => 'user',
        'password' => 'password',
        'database_name' => 'testdb',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->not->toBeEmpty();
})->with([
    'mysql' => ['mysql', 33061],      // Wrong MySQL port
    'postgres' => ['postgres', 54321], // Wrong PostgreSQL port
]);

test('sqlite connection fails', function (string $path, string $expectedMessage) {
    $result = DatabaseConnectionTester::test([
        'database_type' => 'sqlite',
        'host' => $path,
        'port' => 0,
        'username' => '',
        'password' => '',
        'database_name' => null,
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain($expectedMessage);
})->with([
    'non-existent file' => ['/path/to/nonexistent/database.sqlite', 'does not exist'],
    'empty path' => ['', 'Database path is required'],
]);

test('sqlite connection fails with invalid sqlite file', function () {
    $backupDir = config('backup.working_directory');
    $invalidPath = "{$backupDir}/not_a_sqlite_file.txt";

    file_put_contents($invalidPath, 'This is not a SQLite database');

    $result = DatabaseConnectionTester::test([
        'database_type' => 'sqlite',
        'host' => $invalidPath,
        'port' => 0,
        'username' => '',
        'password' => '',
        'database_name' => null,
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Invalid SQLite database file');

    unlink($invalidPath);
});

test('connection fails with unsupported database type', function () {
    $result = DatabaseConnectionTester::test([
        'database_type' => 'mongodb',
        'host' => 'localhost',
        'port' => 27017,
        'username' => 'user',
        'password' => 'password',
        'database_name' => 'testdb',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Unsupported database type');
});
