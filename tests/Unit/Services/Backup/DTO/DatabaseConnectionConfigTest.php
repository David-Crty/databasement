<?php

use App\Enums\DatabaseType;
use App\Services\Backup\DTO\DatabaseConnectionConfig;

test('requiresSshTunnel', function (DatabaseType $type, ?array $sshConfig, bool $expected) {
    $config = new DatabaseConnectionConfig(
        databaseType: $type,
        serverName: 'Test Server',
        host: $type === DatabaseType::SQLITE ? '' : 'db.example.com',
        port: $type === DatabaseType::SQLITE ? 0 : 3306,
        username: $type === DatabaseType::SQLITE ? '' : 'root',
        password: $type === DatabaseType::SQLITE ? '' : 'secret',
        sshConfig: $sshConfig,
    );

    expect($config->requiresSshTunnel())->toBe($expected);
})->with([
    'true when sshConfig is set and not SQLite' => [DatabaseType::MYSQL, ['host' => 'ssh.example.com', 'port' => 22, 'username' => 'deploy'], true],
    'false for SQLite even with sshConfig' => [DatabaseType::SQLITE, ['host' => 'ssh.example.com', 'port' => 22, 'username' => 'deploy'], false],
    'false when sshConfig is null' => [DatabaseType::MYSQL, null, false],
]);

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
