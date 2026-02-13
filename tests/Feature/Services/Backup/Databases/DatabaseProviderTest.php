<?php

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Databases\RedisDatabase;
use App\Services\Backup\Databases\SqliteDatabase;
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
