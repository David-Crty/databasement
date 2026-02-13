<?php

use App\Facades\DatabaseConnectionTester;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Services\Backup\Databases\DatabaseFactory;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\SshTunnelService;

test('test with SSH config for SQLite uses SFTP path', function () {
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

    // SQLite with SSH: requiresSftpTransfer() returns true, so it uses the SFTP test path.
    // The SFTP connection will fail since the SSH server doesn't exist.
    $result = DatabaseConnectionTester::test($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('SFTP connection failed');
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

    $tester = new \App\Services\DatabaseConnectionTester(
        $mockFactory,
        $mockSshService,
        new \App\Services\Backup\Filesystems\SftpFilesystem,
    );

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

test('testSftp returns error when sqlite_path is empty', function () {
    $sshConfig = new DatabaseServerSshConfig;
    $sshConfig->host = 'bastion.example.com';
    $sshConfig->port = 22;
    $sshConfig->username = 'test';
    $sshConfig->auth_type = 'password';
    $sshConfig->password = 'test';

    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'sqlite',
        'host' => '',
        'port' => 0,
        'username' => '',
        'password' => '',
        'sqlite_path' => '',
    ], $sshConfig);

    $result = DatabaseConnectionTester::test($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Database file path is required.');
});

test('testSftp returns success when file exists on remote', function () {
    $sshConfig = new DatabaseServerSshConfig;
    $sshConfig->host = 'bastion.example.com';
    $sshConfig->port = 22;
    $sshConfig->username = 'test';
    $sshConfig->auth_type = 'password';
    $sshConfig->password = 'test';

    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'sqlite',
        'host' => '',
        'port' => 0,
        'username' => '',
        'password' => '',
        'sqlite_path' => '/data/app.sqlite',
    ], $sshConfig);

    // Mock the SftpFilesystem to simulate a successful SFTP connection
    $mockFilesystem = Mockery::mock(\League\Flysystem\Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->with('/data/app.sqlite')->andReturn(true);
    $mockFilesystem->shouldReceive('fileSize')->with('/data/app.sqlite')->andReturn(4096);

    $mockSftpFilesystem = Mockery::mock(\App\Services\Backup\Filesystems\SftpFilesystem::class);
    $mockSftpFilesystem->shouldReceive('getFromSshConfig')->andReturn($mockFilesystem);

    $tester = new \App\Services\DatabaseConnectionTester(
        new DatabaseFactory,
        Mockery::mock(SshTunnelService::class),
        $mockSftpFilesystem,
    );

    $result = $tester->test($server);

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details']['sftp'])->toBeTrue()
        ->and($result['details']['ssh_host'])->toBe('bastion.example.com');
});

test('testSftp returns error when file does not exist on remote', function () {
    $sshConfig = new DatabaseServerSshConfig;
    $sshConfig->host = 'bastion.example.com';
    $sshConfig->port = 22;
    $sshConfig->username = 'test';
    $sshConfig->auth_type = 'password';
    $sshConfig->password = 'test';

    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'sqlite',
        'host' => '',
        'port' => 0,
        'username' => '',
        'password' => '',
        'sqlite_path' => '/data/missing.sqlite',
    ], $sshConfig);

    $mockFilesystem = Mockery::mock(\League\Flysystem\Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->with('/data/missing.sqlite')->andReturn(false);

    $mockSftpFilesystem = Mockery::mock(\App\Services\Backup\Filesystems\SftpFilesystem::class);
    $mockSftpFilesystem->shouldReceive('getFromSshConfig')->andReturn($mockFilesystem);

    $tester = new \App\Services\DatabaseConnectionTester(
        new DatabaseFactory,
        Mockery::mock(SshTunnelService::class),
        $mockSftpFilesystem,
    );

    $result = $tester->test($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Remote file does not exist: /data/missing.sqlite');
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
