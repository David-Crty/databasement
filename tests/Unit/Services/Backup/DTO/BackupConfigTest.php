<?php

use App\Enums\CompressionType;
use App\Enums\DatabaseType;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
use App\Services\Backup\DTO\VolumeConfig;

test('toPayload and fromPayload are symmetric', function () {
    $original = new BackupConfig(
        database: new DatabaseConnectionConfig(
            databaseType: DatabaseType::MYSQL,
            serverName: 'Prod Server',
            host: 'db.example.com',
            port: 3306,
            username: 'root',
            password: 'secret',
        ),
        volume: new VolumeConfig(
            type: 'local',
            name: 'Local',
            config: ['path' => '/backups'],
        ),
        databaseName: 'myapp',
        workingDirectory: '/tmp/work',
        backupPath: 'backups/2026/02',
        compressionType: CompressionType::GZIP,
        compressionLevel: 6,
    );

    $payload = $original->toPayload();
    $restored = BackupConfig::fromPayload($payload, '/tmp/restored');

    expect($restored->database->databaseType)->toBe(DatabaseType::MYSQL)
        ->and($restored->database->host)->toBe('db.example.com')
        ->and($restored->database->password)->toBe('secret')
        ->and($restored->database->serverName)->toBe('Prod Server')
        ->and($restored->volume->type)->toBe('local')
        ->and($restored->volume->name)->toBe('Local')
        ->and($restored->volume->config)->toBe(['path' => '/backups'])
        ->and($restored->databaseName)->toBe('myapp')
        ->and($restored->workingDirectory)->toBe('/tmp/restored')
        ->and($restored->backupPath)->toBe('backups/2026/02')
        ->and($restored->compressionType)->toBe(CompressionType::GZIP)
        ->and($restored->compressionLevel)->toBe(6);
});

test('toPayload includes server_name and database_name in database section', function () {
    $config = new BackupConfig(
        database: new DatabaseConnectionConfig(
            databaseType: DatabaseType::POSTGRESQL,
            serverName: 'PG Prod',
            host: 'pg.example.com',
            port: 5432,
            username: 'admin',
            password: 'pass',
        ),
        volume: new VolumeConfig(type: 's3', name: 'Bucket', config: []),
        databaseName: 'analytics',
        workingDirectory: '/tmp/work',
    );

    $payload = $config->toPayload();

    expect($payload['server_name'])->toBe('PG Prod')
        ->and($payload['database']['database_name'])->toBe('analytics')
        ->and($payload['compression']['type'])->toBeNull()
        ->and($payload['compression']['level'])->toBeNull();
});
