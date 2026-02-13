<?php

use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;

test('forConnectionTest creates temporary server with SSH config', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create([
        'host' => 'bastion.example.com',
        'username' => 'tunnel_user',
    ]);

    $server = DatabaseServer::forConnectionTest([
        'host' => 'private-db.internal',
        'port' => 3306,
        'database_type' => 'mysql',
        'username' => 'dbuser',
        'password' => 'secret',
    ], $sshConfig);

    expect($server->host)->toBe('private-db.internal')
        ->and($server->port)->toBe(3306)
        ->and($server->username)->toBe('dbuser')
        ->and($server->requiresSshTunnel())->toBeTrue()
        ->and($server->sshConfig)->toBe($sshConfig)
        ->and($server->exists)->toBeFalse(); // Not persisted
});

test('forConnectionTest creates temporary server without SSH config', function () {
    $server = DatabaseServer::forConnectionTest([
        'host' => 'db.example.com',
        'port' => 5432,
        'database_type' => 'postgres',
        'username' => 'pguser',
        'password' => 'secret',
    ]);

    expect($server->host)->toBe('db.example.com')
        ->and($server->port)->toBe(5432)
        ->and($server->requiresSshTunnel())->toBeFalse()
        ->and($server->sshConfig)->toBeNull()
        ->and($server->exists)->toBeFalse();
});

test('forConnectionTest uses default port when not specified', function () {
    $server = DatabaseServer::forConnectionTest([
        'host' => 'db.example.com',
    ]);

    expect($server->port)->toBe(3306); // Default MySQL port
});

test('getConnectionLabel returns basename for SQLite', function () {
    $server = DatabaseServer::factory()->make([
        'database_type' => 'sqlite',
        'sqlite_path' => '/var/data/myapp.sqlite',
    ]);

    expect($server->getConnectionLabel())->toBe('myapp.sqlite')
        ->and($server->getConnectionDetails())->toBe('/var/data/myapp.sqlite');
});

test('getConnectionLabel returns host:port for client-server databases', function () {
    $server = DatabaseServer::factory()->make([
        'database_type' => 'mysql',
        'host' => 'db.example.com',
        'port' => 3306,
    ]);

    expect($server->getConnectionLabel())->toBe('db.example.com:3306')
        ->and($server->getConnectionDetails())->toBe('db.example.com:3306');
});

test('getSshDisplayName returns null when SSH not configured', function () {
    $server = DatabaseServer::factory()->make();

    expect($server->getSshDisplayName())->toBeNull();
});

test('getSshDisplayName returns display name when SSH configured', function () {
    $server = DatabaseServer::factory()->withSshTunnel()->create();

    expect($server->getSshDisplayName())->not->toBeNull()
        ->and($server->getSshDisplayName())->toContain('@'); // Format: user@host:port
});

test('requiresSftpTransfer returns correct value', function () {
    $sqliteWithSsh = DatabaseServer::factory()->sqliteRemote()->create();
    $sqliteLocal = DatabaseServer::factory()->sqlite()->create();
    $mysqlWithSsh = DatabaseServer::factory()->withSshTunnel()->create();

    expect($sqliteWithSsh->ssh_config_id)->not->toBeNull()
        ->and($sqliteWithSsh->requiresSftpTransfer())->toBeTrue()
        ->and($sqliteLocal->requiresSftpTransfer())->toBeFalse()
        ->and($mysqlWithSsh->requiresSftpTransfer())->toBeFalse();
});
