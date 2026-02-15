<?php

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Databases\RedisDatabase;
use App\Services\Backup\Databases\SqliteDatabase;
use App\Services\Backup\Filesystems\SftpFilesystem;
use App\Services\SshTunnelService;

test('make returns correct handler for database type', function (DatabaseType $type, string $expectedClass) {
    $factory = new DatabaseProvider;

    expect($factory->make($type))->toBeInstanceOf($expectedClass);
})->with([
    'mysql' => [DatabaseType::MYSQL, MysqlDatabase::class],
    'postgresql' => [DatabaseType::POSTGRESQL, PostgresqlDatabase::class],
    'sqlite' => [DatabaseType::SQLITE, SqliteDatabase::class],
    'redis' => [DatabaseType::REDIS, RedisDatabase::class],
]);

test('makeForServer uses explicit host and port parameters', function () {
    $server = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'host' => 'private-db.internal',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $factory = new DatabaseProvider;

    // Simulate SSH tunnel override: pass different host/port than model
    $database = $factory->makeForServer($server, 'myapp', '127.0.0.1', 54321);

    $result = $database->dump('/tmp/test.sql');
    expect($result->command)->toContain("--host='127.0.0.1'")
        ->toContain("--port='54321'")
        ->not->toContain('private-db.internal');
});

test('listDatabasesForServer delegates to handler listDatabases', function () {
    $server = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'host' => 'db.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('listDatabases')
        ->once()
        ->andReturn(['app_db', 'test_db']);

    $sshTunnelService = Mockery::mock(SshTunnelService::class);
    $sshTunnelService->shouldReceive('close')->once();

    $factory = Mockery::mock(DatabaseProvider::class, [new \App\Services\Backup\Filesystems\SftpFilesystem, $sshTunnelService])
        ->makePartial();
    $factory->shouldReceive('makeForServer')
        ->once()
        ->with($server, '', 'db.local', 3306)
        ->andReturn($mockHandler);

    $databases = $factory->listDatabasesForServer($server);

    expect($databases)->toBe(['app_db', 'test_db']);
});

test('testConnectionForServer delegates to handler testConnection', function (string $dbType, string $expectedDbName) {
    $mockHandler = Mockery::mock(DatabaseInterface::class);
    $mockHandler->shouldReceive('testConnection')
        ->once()
        ->andReturn(['success' => true, 'message' => 'Connection successful', 'details' => []]);

    $mockSshService = Mockery::mock(SshTunnelService::class);
    $mockSshService->shouldReceive('close')->once();

    $provider = Mockery::mock(DatabaseProvider::class, [new SftpFilesystem, $mockSshService])
        ->makePartial();
    $provider->shouldReceive('makeForServer')
        ->once()
        ->with(
            Mockery::type(DatabaseServer::class),
            $expectedDbName,
            Mockery::type('string'),
            Mockery::type('int')
        )
        ->andReturn($mockHandler);

    $server = DatabaseServer::forConnectionTest([
        'database_type' => $dbType,
        'host' => 'db.example.com',
        'port' => $dbType === 'postgres' ? 5432 : 3306,
        'username' => 'user',
        'password' => 'pass',
    ]);

    $result = $provider->testConnectionForServer($server);

    expect($result['success'])->toBeTrue();
})->with([
    'mysql uses empty database name' => ['mysql', ''],
    'postgresql uses postgres database' => ['postgres', 'postgres'],
    'redis uses empty database name' => ['redis', ''],
]);

test('testConnectionForServer routes remote SQLite to SFTP test', function () {
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
        'database_names' => ['/path/to/database.sqlite'],
    ], $sshConfig);

    // SQLite with SSH: requiresSftpTransfer() returns true, so it uses the SFTP test path.
    // The SFTP connection will fail since the SSH server doesn't exist.
    $result = app(DatabaseProvider::class)->testConnectionForServer($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('SFTP connection failed');
});

test('testConnectionForServer returns SSH failure', function () {
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

    $result = app(DatabaseProvider::class)->testConnectionForServer($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('SSH connection failed');
});

test('testConnectionForServer returns error when database_names is empty for remote SQLite', function () {
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
        'database_names' => [],
    ], $sshConfig);

    $result = app(DatabaseProvider::class)->testConnectionForServer($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Database file path is required.');
});

test('testConnectionForServer returns SFTP success when file exists', function () {
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
        'database_names' => ['/data/app.sqlite'],
    ], $sshConfig);

    // Mock the SftpFilesystem to simulate a successful SFTP connection
    $mockFilesystem = Mockery::mock(\League\Flysystem\Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->with('/data/app.sqlite')->andReturn(true);
    $mockFilesystem->shouldReceive('fileSize')->with('/data/app.sqlite')->andReturn(4096);

    $mockSftpFilesystem = Mockery::mock(SftpFilesystem::class);
    $mockSftpFilesystem->shouldReceive('getFromSshConfig')->andReturn($mockFilesystem);

    $provider = new DatabaseProvider(
        $mockSftpFilesystem,
        Mockery::mock(SshTunnelService::class),
    );

    $result = $provider->testConnectionForServer($server);

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details']['sftp'])->toBeTrue()
        ->and($result['details']['ssh_host'])->toBe('bastion.example.com');
});

test('testConnectionForServer tests all SFTP paths and reports failures', function () {
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
        'database_names' => ['/data/app.sqlite', '/data/missing.sqlite', '/data/other.sqlite'],
    ], $sshConfig);

    $mockFilesystem = Mockery::mock(\League\Flysystem\Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->with('/data/app.sqlite')->andReturn(true);
    $mockFilesystem->shouldReceive('fileSize')->with('/data/app.sqlite')->andReturn(4096);
    $mockFilesystem->shouldReceive('fileExists')->with('/data/missing.sqlite')->andReturn(false);
    $mockFilesystem->shouldReceive('fileExists')->with('/data/other.sqlite')->andReturn(false);

    $mockSftpFilesystem = Mockery::mock(SftpFilesystem::class);
    $mockSftpFilesystem->shouldReceive('getFromSshConfig')->andReturn($mockFilesystem);

    $provider = new DatabaseProvider(
        $mockSftpFilesystem,
        Mockery::mock(SshTunnelService::class),
    );

    $result = $provider->testConnectionForServer($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('/data/missing.sqlite')
        ->and($result['message'])->toContain('/data/other.sqlite')
        ->and($result['message'])->not->toContain('/data/app.sqlite');
});

test('testConnectionForServer tests all local SQLite paths', function () {
    $dbDir = sys_get_temp_dir().'/sqlite-db-test-'.uniqid();
    mkdir($dbDir, 0755, true);

    // Create two valid SQLite databases
    $path1 = $dbDir.'/db1.sqlite';
    $path2 = $dbDir.'/db2.sqlite';
    (new \PDO("sqlite:{$path1}"))->exec('CREATE TABLE t1 (id INTEGER)');
    (new \PDO("sqlite:{$path2}"))->exec('CREATE TABLE t2 (id INTEGER)');

    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'sqlite',
        'host' => '',
        'port' => 0,
        'username' => '',
        'password' => '',
        'database_names' => [$path1, $path2],
    ]);

    $result = app(DatabaseProvider::class)->testConnectionForServer($server);

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful');

    $output = json_decode($result['details']['output'], true);
    expect($output)->toHaveCount(2)
        ->and($output[0]['path'])->toBe($path1)
        ->and($output[1]['path'])->toBe($path2);
});

test('testConnectionForServer reports failing local SQLite paths', function () {
    $dbDir = sys_get_temp_dir().'/sqlite-db-test-'.uniqid();
    mkdir($dbDir, 0755, true);

    $validPath = $dbDir.'/valid.sqlite';
    (new \PDO("sqlite:{$validPath}"))->exec('CREATE TABLE t1 (id INTEGER)');
    $missingPath = $dbDir.'/missing.sqlite';

    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'sqlite',
        'host' => '',
        'port' => 0,
        'username' => '',
        'password' => '',
        'database_names' => [$validPath, $missingPath],
    ]);

    $result = app(DatabaseProvider::class)->testConnectionForServer($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain($missingPath)
        ->and($result['message'])->not->toContain($validPath);
});

test('testConnectionForServer returns SFTP error when file missing', function () {
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
        'database_names' => ['/data/missing.sqlite'],
    ], $sshConfig);

    $mockFilesystem = Mockery::mock(\League\Flysystem\Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->with('/data/missing.sqlite')->andReturn(false);

    $mockSftpFilesystem = Mockery::mock(SftpFilesystem::class);
    $mockSftpFilesystem->shouldReceive('getFromSshConfig')->andReturn($mockFilesystem);

    $provider = new DatabaseProvider(
        $mockSftpFilesystem,
        Mockery::mock(SshTunnelService::class),
    );

    $result = $provider->testConnectionForServer($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Remote file does not exist: /data/missing.sqlite');
});
