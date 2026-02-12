<?php

use App\Facades\DatabaseConnectionTester;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Services\Backup\Databases\DatabaseFactory;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\SshTunnelService;

test('test with SSH config returns error for SQLite', function () {
    $sshConfig = new DatabaseServerSshConfig;
    $sshConfig->host = 'bastion.example.com';
    $sshConfig->port = 22;
    $sshConfig->username = 'test';
    $sshConfig->auth_type = 'password';
    $sshConfig->password = 'test';

    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'sqlite',
        'host' => '/path/to/database.sqlite',
        'port' => 0,
        'username' => '',
        'password' => '',
        'sqlite_path' => '/path/to/database.sqlite',
    ], $sshConfig);

    // SQLite with SSH: requiresSshTunnel() returns false because database_type is SQLITE,
    // so it skips SSH and goes straight to testDatabase — which tests the SQLite file directly.
    // The SQLite file doesn't exist, so we expect a file-not-found error.
    $result = DatabaseConnectionTester::test($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('does not exist');
});

test('test with SSH config fails when SSH connection fails', function () {
    $sshConfig = new DatabaseServerSshConfig;
    $sshConfig->host = 'nonexistent.invalid.host.example';
    $sshConfig->port = 22;
    $sshConfig->username = 'test';
    $sshConfig->auth_type = 'password';
    $sshConfig->password = 'test';

    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'mysql',
        'host' => 'db.internal',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ], $sshConfig);

    $result = DatabaseConnectionTester::test($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('SSH connection failed');
});

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

test('test delegates to handler with correct database name', function (string $dbType, string $expectedDbName) {
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('testConnection')
        ->once()
        ->andReturn(['success' => true, 'message' => 'Connection successful', 'details' => []]);

    $mockFactory = Mockery::mock(DatabaseFactory::class);
    $mockFactory->shouldReceive('makeForServer')
        ->once()
        ->with(
            Mockery::type(DatabaseServer::class),
            $expectedDbName,
            Mockery::type('string'),
            Mockery::type('int')
        )
        ->andReturn($mockHandler);

    $mockSshService = Mockery::mock(SshTunnelService::class);
    $mockSshService->shouldReceive('close')->once();

    $tester = new \App\Services\DatabaseConnectionTester($mockFactory, $mockSshService);

    $server = DatabaseServer::forConnectionTest([
        'database_type' => $dbType,
        'host' => 'db.example.com',
        'port' => $dbType === 'postgres' ? 5432 : 3306,
        'username' => 'user',
        'password' => 'pass',
    ]);

    $result = $tester->test($server);

    expect($result['success'])->toBeTrue();
})->with([
    'mysql uses empty database name' => ['mysql', ''],
    'postgresql uses postgres database' => ['postgres', 'postgres'],
    'redis uses empty database name' => ['redis', ''],
]);
