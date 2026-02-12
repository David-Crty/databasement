<?php

/**
 * Integration tests for DatabaseConnectionTester with real databases.
 *
 * These tests require MySQL and PostgreSQL containers to be running.
 * Run with: php artisan test --group=integration
 */

use App\Facades\AppConfig;
use App\Facades\DatabaseConnectionTester;
use App\Models\DatabaseServer;
use Tests\Support\IntegrationTestHelpers;

test('connection succeeds', function (string $databaseType) {
    $config = IntegrationTestHelpers::getDatabaseConfig($databaseType);

    if ($databaseType === 'sqlite') {
        IntegrationTestHelpers::createTestSqliteDatabase($config['host']);
    } elseif ($databaseType === 'redis') {
        // Redis doesn't need test data for connection testing
    } else {
        // Create unique database for this parallel process
        $server = IntegrationTestHelpers::createDatabaseServer($databaseType);
        IntegrationTestHelpers::loadTestData($databaseType, $server);
    }

    $testServer = DatabaseServer::forConnectionTest([
        'database_type' => $databaseType,
        'host' => $config['host'],
        'port' => $config['port'],
        'username' => $config['username'],
        'password' => $config['password'],
        'sqlite_path' => $databaseType === 'sqlite' ? $config['host'] : null,
    ]);

    $result = DatabaseConnectionTester::test($testServer);

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful');

    // Cleanup
    if ($databaseType === 'sqlite') {
        unlink($config['host']);
    } elseif ($databaseType !== 'redis') {
        IntegrationTestHelpers::dropDatabase($databaseType, $server, $config['database']);
        $server->delete();
    }
})->with(['mysql', 'postgres', 'sqlite', 'redis']);

test('connection fails with invalid credentials', function (string $databaseType) {
    $config = IntegrationTestHelpers::getDatabaseConfig($databaseType);

    $server = DatabaseServer::forConnectionTest([
        'database_type' => $databaseType,
        'host' => $config['host'],
        'port' => $config['port'],
        'username' => 'invalid_user',
        'password' => 'invalid_password',
    ]);

    $result = DatabaseConnectionTester::test($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->not->toBeEmpty();
})->with(['mysql', 'postgres']);

test('connection fails with unreachable host', function (string $databaseType, int $port) {
    $server = DatabaseServer::forConnectionTest([
        'database_type' => $databaseType,
        'host' => '127.0.0.1',
        'port' => $port, // Wrong port - nothing listening here
        'username' => 'user',
        'password' => 'password',
    ]);

    $result = DatabaseConnectionTester::test($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->not->toBeEmpty();
})->with([
    'mysql' => ['mysql', 33061],      // Wrong MySQL port
    'postgres' => ['postgres', 54321], // Wrong PostgreSQL port
]);

test('sqlite connection fails', function (string $path, string $expectedMessage) {
    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'sqlite',
        'host' => $path,
        'port' => 0,
        'username' => '',
        'password' => '',
        'sqlite_path' => $path,
    ]);

    $result = DatabaseConnectionTester::test($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain($expectedMessage);
})->with([
    'non-existent file' => ['/path/to/nonexistent/database.sqlite', 'does not exist'],
    'empty path' => ['', 'Database path is required'],
]);

test('sqlite connection fails with invalid sqlite file', function () {
    $backupDir = AppConfig::get('backup.working_directory');
    $invalidPath = "{$backupDir}/not_a_sqlite_file.txt";

    file_put_contents($invalidPath, 'This is not a SQLite database');

    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'sqlite',
        'host' => $invalidPath,
        'port' => 0,
        'username' => '',
        'password' => '',
        'sqlite_path' => $invalidPath,
    ]);

    $result = DatabaseConnectionTester::test($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Invalid SQLite database file');

    unlink($invalidPath);
});

test('redis connection fails with wrong port', function () {
    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'redis',
        'host' => '127.0.0.1',
        'port' => 63791, // Wrong port
        'username' => '',
        'password' => '',
    ]);

    $result = DatabaseConnectionTester::test($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->not->toBeEmpty();
});
