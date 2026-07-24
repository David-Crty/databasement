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
        volumes: [new VolumeConfig(
            type: 'local',
            name: 'Local',
            config: ['path' => '/backups'],
        )],
        databaseName: 'myapp',
        workingDirectory: '/tmp/work',
        backupPath: 'backups/2026/02',
        compressionType: CompressionType::ZSTD,
        compressionLevel: 6,
        compressionMultithread: true,
        postBackupScript: '/usr/local/bin/notify.sh',
    );

    $payload = $original->toPayload();
    $restored = BackupConfig::fromPayload($payload, '/tmp/restored');

    expect($restored->database->databaseType)->toBe(DatabaseType::MYSQL)
        ->and($restored->database->host)->toBe('db.example.com')
        ->and($restored->database->password)->toBe('secret')
        ->and($restored->database->serverName)->toBe('Prod Server')
        ->and($restored->volumes)->toHaveCount(1)
        ->and($restored->volumes[0]->type)->toBe('local')
        ->and($restored->volumes[0]->name)->toBe('Local')
        ->and($restored->volumes[0]->config)->toBe(['path' => '/backups'])
        ->and($restored->databaseName)->toBe('myapp')
        ->and($restored->workingDirectory)->toBe('/tmp/restored')
        ->and($restored->backupPath)->toBe('backups/2026/02')
        ->and($restored->compressionType)->toBe(CompressionType::ZSTD)
        ->and($restored->compressionLevel)->toBe(6)
        ->and($restored->compressionMultithread)->toBeTrue()
        ->and($restored->postBackupScript)->toBe('/usr/local/bin/notify.sh');
});

test('fromPayload wraps a legacy single-volume payload', function () {
    // Payloads created before multi-volume support (still queued in
    // agent_jobs) only carry the single `volume` key.
    $config = BackupConfig::fromPayload([
        'database' => [
            'type' => 'mysql',
            'host' => 'db.example.com',
            'port' => 3306,
            'username' => 'root',
            'password' => 'secret',
            'database_name' => 'myapp',
        ],
        'volume' => ['type' => 's3', 'name' => 'Bucket', 'config' => ['bucket' => 'b']],
        'compression' => ['type' => null, 'level' => null],
        'server_name' => 'Prod Server',
    ], '/tmp/work');

    expect($config->volumes)->toHaveCount(1)
        ->and($config->volumes[0]->type)->toBe('s3')
        ->and($config->volumes[0]->name)->toBe('Bucket');
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
        volumes: [new VolumeConfig(type: 's3', name: 'Bucket', config: [])],
        databaseName: 'analytics',
        workingDirectory: '/tmp/work',
    );

    $payload = $config->toPayload();

    expect($payload['server_name'])->toBe('PG Prod')
        ->and($payload['database']['database_name'])->toBe('analytics')
        ->and($payload['compression']['type'])->toBeNull()
        ->and($payload['compression']['level'])->toBeNull();
});
