<?php

use App\Enums\DatabaseType;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Volume;
use App\Services\DemoBackupService;

test('creates demo backup for sqlite database', function () {
    // Test is run with SQLite by default (phpunit.xml config)
    // This mirrors the production scenario where the app uses SQLite
    config(['database.connections.sqlite.database' => '/data/database.sqlite']);

    $service = new DemoBackupService;
    $databaseServer = $service->createDemoBackup('sqlite');

    expect($databaseServer)->toBeInstanceOf(DatabaseServer::class)
        ->and($databaseServer->database_type)->toBe(DatabaseType::SQLITE)
        ->and($databaseServer->sqlite_path)->toBe('/data/database.sqlite')
        ->and($databaseServer->host)->toBeNull()
        ->and($databaseServer->username)->toBeNull()
        ->and(Volume::count())->toBe(1)
        ->and(Backup::count())->toBe(1)
        ->and($databaseServer->backup)->not->toBeNull();

});

test('creates demo backup for mysql database', function () {
    // Set up a fake mysql connection config - no actual connection is made
    config(['database.connections.mysql' => [
        'driver' => 'mysql',
        'host' => 'mysql.example.com',
        'port' => 3306,
        'database' => 'myapp',
        'username' => 'dbuser',
        'password' => 'secret',
    ]]);

    $service = new DemoBackupService;
    $databaseServer = $service->createDemoBackup('mysql');

    expect($databaseServer)->toBeInstanceOf(DatabaseServer::class)
        ->and($databaseServer->database_type)->toBe(DatabaseType::MYSQL)
        ->and($databaseServer->host)->toBe('mysql.example.com')
        ->and($databaseServer->port)->toBe(3306)
        ->and($databaseServer->username)->toBe('dbuser')
        ->and($databaseServer->sqlite_path)->toBeNull()
        ->and(Volume::count())->toBe(1)
        ->and(Backup::count())->toBe(1)
        ->and($databaseServer->backup)->not->toBeNull();
});

test('creates demo backup for postgresql database', function () {
    // Set up a fake pgsql connection config - no actual connection is made
    config(['database.connections.pgsql' => [
        'driver' => 'pgsql',
        'host' => 'postgres.example.com',
        'port' => 5432,
        'database' => 'myapp',
        'username' => 'pguser',
        'password' => 'secret',
    ]]);

    $service = new DemoBackupService;
    $databaseServer = $service->createDemoBackup('pgsql');

    expect($databaseServer)->toBeInstanceOf(DatabaseServer::class)
        ->and($databaseServer->database_type)->toBe(DatabaseType::POSTGRESQL)
        ->and($databaseServer->host)->toBe('postgres.example.com')
        ->and($databaseServer->port)->toBe(5432)
        ->and($databaseServer->username)->toBe('pguser')
        ->and($databaseServer->sqlite_path)->toBeNull()
        ->and(Volume::count())->toBe(1)
        ->and(Backup::count())->toBe(1)
        ->and($databaseServer->backup)->not->toBeNull();
});

test('throws exception for unsupported database type', function () {
    $service = new DemoBackupService;
    $service->createDemoBackup('mongodb');
})->throws(RuntimeException::class, 'Unsupported database connection: mongodb');
