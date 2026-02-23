<?php

use App\Enums\DatabaseType;
use App\Services\Backup\DTO\DatabaseConnectionConfig;

test('requiresSshTunnel returns true when sshConfig is set and not SQLite', function () {
    $config = new DatabaseConnectionConfig(
        databaseType: DatabaseType::MYSQL,
        serverName: 'MySQL Server',
        host: 'db.example.com',
        port: 3306,
        username: 'root',
        password: 'secret',
        sshConfig: ['host' => 'ssh.example.com', 'port' => 22, 'username' => 'deploy'],
    );

    expect($config->requiresSshTunnel())->toBeTrue();
});

test('requiresSshTunnel returns false for SQLite even with sshConfig', function () {
    $config = new DatabaseConnectionConfig(
        databaseType: DatabaseType::SQLITE,
        serverName: 'SQLite Server',
        host: '',
        port: 0,
        username: '',
        password: '',
        sshConfig: ['host' => 'ssh.example.com', 'port' => 22, 'username' => 'deploy'],
    );

    expect($config->requiresSshTunnel())->toBeFalse();
});

test('requiresSshTunnel returns false when sshConfig is null', function () {
    $config = new DatabaseConnectionConfig(
        databaseType: DatabaseType::MYSQL,
        serverName: 'MySQL Server',
        host: 'localhost',
        port: 3306,
        username: 'root',
        password: 'secret',
    );

    expect($config->requiresSshTunnel())->toBeFalse();
});

test('getSafeSshConfig returns sanitized config without sensitive fields', function () {
    $config = new DatabaseConnectionConfig(
        databaseType: DatabaseType::MYSQL,
        serverName: 'MySQL Server',
        host: 'db.example.com',
        port: 3306,
        username: 'root',
        password: 'secret',
        sshConfig: [
            'host' => 'ssh.example.com',
            'port' => 2222,
            'username' => 'deploy',
            'auth_type' => 'key',
            'password' => 'should-be-excluded',
            'private_key' => 'should-be-excluded',
            'key_passphrase' => 'should-be-excluded',
        ],
    );

    $safe = $config->getSafeSshConfig();

    expect($safe)->toBe([
        'host' => 'ssh.example.com',
        'port' => 2222,
        'username' => 'deploy',
        'auth_type' => 'key',
    ]);
});

test('getSafeSshConfig returns null when no SSH config', function () {
    $config = new DatabaseConnectionConfig(
        databaseType: DatabaseType::MYSQL,
        serverName: 'MySQL Server',
        host: 'localhost',
        port: 3306,
        username: 'root',
        password: 'secret',
    );

    expect($config->getSafeSshConfig())->toBeNull();
});
