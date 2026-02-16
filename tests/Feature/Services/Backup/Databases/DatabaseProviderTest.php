<?php

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\Databases\MongodbDatabase;
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
    'mongodb' => [DatabaseType::MONGODB, MongodbDatabase::class],
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

test('makeForServer passes auth_source from extra_config for mongodb', function () {
    $server = DatabaseServer::factory()->mongodb()->create();

    $factory = new DatabaseProvider;

    $database = $factory->makeForServer($server, 'mydb', '127.0.0.1', 27017);

    $result = $database->dump('/tmp/dump.archive');
    expect($result->command)->toContain("--authenticationDatabase='admin'")
        ->toContain("--db='mydb'");
});

test('makeForServer passes sourceDatabaseName for mongodb restore', function () {
    $server = DatabaseServer::factory()->mongodb()->create();

    $factory = new DatabaseProvider;

    $database = $factory->makeForServer($server, 'targetdb', '127.0.0.1', 27017, 'sourcedb');

    $result = $database->restore('/tmp/dump.archive');
    expect($result->command)->toContain("--nsFrom='sourcedb.*'")
        ->toContain("--nsTo='targetdb.*'");
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
    'mongodb uses empty database name' => ['mongodb', ''],
]);

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
