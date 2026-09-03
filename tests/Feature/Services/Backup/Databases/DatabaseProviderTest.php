<?php

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Services\Backup\Databases\DatabaseInterface;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\Databases\FirebirdDatabase;
use App\Services\Backup\Databases\MongodbDatabase;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Databases\RedisDatabase;
use App\Services\Backup\Databases\S3Database;
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
    'firebird' => [DatabaseType::FIREBIRD, FirebirdDatabase::class],
    's3' => [DatabaseType::S3, S3Database::class],
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

test('makeForServer handles unsaved forConnectionTest server (no name)', function () {
    // The form's "Test Connection" and "Load Databases" flows build a transient
    // DatabaseServer via forConnectionTest() without a name. This must not blow up
    // when threaded through the DTO conversion in makeForServer.
    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'mysql',
        'host' => 'db.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $factory = new DatabaseProvider;
    $database = $factory->makeForServer($server, 'myapp', 'db.local', 3306);

    expect($database)->toBeInstanceOf(MysqlDatabase::class);
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
    expect($result->command)->toContain('authSource=admin')
        ->toContain('/mydb');
});

test('makeForServer passes ssl_enabled from extra_config for mysql', function () {
    $server = DatabaseServer::factory()->create([
        'database_type' => 'mysql',
        'host' => 'db.example.com',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'extra_config' => ['ssl_enabled' => true],
    ]);

    $database = (new DatabaseProvider)->makeForServer($server, 'myapp', 'db.example.com', 3306);

    expect($database->dump('/tmp/test.sql')->command)
        ->toContain('--ssl --ssl-verify-server-cert=0')
        ->not->toContain('--skip_ssl');
});

test('makeFromConfig passes ssl_enabled from extra_config for mysql', function () {
    $config = new \App\Services\Backup\DTO\DatabaseConnectionConfig(
        databaseType: DatabaseType::MYSQL,
        serverName: 'MySQL Server',
        host: 'db.example.com',
        port: 3306,
        username: 'root',
        password: 'secret',
        extraConfig: ['ssl_enabled' => true],
    );

    $database = (new DatabaseProvider)->makeFromConfig($config, 'myapp', 'db.example.com', 3306);

    expect($database->dump('/tmp/test.sql')->command)
        ->toContain('--ssl --ssl-verify-server-cert=0')
        ->not->toContain('--skip_ssl');
});

test('makeFromConfig passes ssl_enabled from extra_config for postgres', function () {
    $config = new \App\Services\Backup\DTO\DatabaseConnectionConfig(
        databaseType: DatabaseType::POSTGRESQL,
        serverName: 'PG Server',
        host: 'pg.example.com',
        port: 5432,
        username: 'postgres',
        password: 'secret',
        extraConfig: ['ssl_enabled' => true],
    );

    $database = (new DatabaseProvider)->makeFromConfig($config, 'myapp', 'pg.example.com', 5432);

    expect($database->dump('/tmp/test.sql')->command)->toStartWith('PGSSLMODE=require ');
});

test('makeFromConfig passes dump_privileges from extra_config for postgres', function () {
    $config = new \App\Services\Backup\DTO\DatabaseConnectionConfig(
        databaseType: DatabaseType::POSTGRESQL,
        serverName: 'PG Server',
        host: 'pg.example.com',
        port: 5432,
        username: 'postgres',
        password: 'secret',
        extraConfig: ['dump_privileges' => true],
    );

    $database = (new DatabaseProvider)->makeFromConfig($config, 'myapp', 'pg.example.com', 5432);

    expect($database->dump('/tmp/test.sql')->command)
        ->not->toContain('--no-owner')
        ->not->toContain('--no-privileges');
});

test('makeFromConfig snapshot dump_privileges overrides target extra_config at restore time', function () {
    $config = new \App\Services\Backup\DTO\DatabaseConnectionConfig(
        databaseType: DatabaseType::POSTGRESQL,
        serverName: 'PG Server',
        host: 'pg.example.com',
        port: 5432,
        username: 'postgres',
        password: 'secret',
        extraConfig: ['dump_privileges' => true],
    );

    // Legacy snapshot dumped without privileges: target server setting must not apply
    $database = (new DatabaseProvider)->makeFromConfig(
        $config, 'myapp', 'pg.example.com', 5432,
        snapshotDumpFormat: 'custom',
        snapshotDumpPrivileges: false,
    );

    expect($database->restore('/tmp/snapshot.dump')->command)
        ->toContain('--no-owner')
        ->toContain('--no-privileges');
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
    'firebird uses empty database name when no paths set' => ['firebird', ''],
]);

test('postgres connection test opens the configured connection database', function () {
    // Reproduces issues #545/#549: a managed instance that denies CONNECT on
    // `postgres`, where the user can only reach their own database.
    $mockHandler = Mockery::mock(\App\Services\Backup\Databases\DatabaseInterface::class);
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
            'app_db',
            Mockery::type('string'),
            Mockery::type('int')
        )
        ->andReturn($mockHandler);

    $server = DatabaseServer::forConnectionTest([
        'database_type' => 'postgres',
        'host' => 'db.example.com',
        'port' => 5432,
        'username' => 'user',
        'password' => 'pass',
        'extra_config' => ['connection_database' => 'app_db'],
    ]);

    expect($provider->testConnectionForServer($server)['success'])->toBeTrue();
});

test('connectionDatabase falls back to postgres', function (array $extraConfig, string $expected) {
    expect(DatabaseProvider::connectionDatabase($extraConfig))->toBe($expected);
})->with([
    'unset' => [[], 'postgres'],
    'empty string' => [['connection_database' => ''], 'postgres'],
    'null' => [['connection_database' => null], 'postgres'],
    'whitespace only' => [['connection_database' => "  \t "], 'postgres'],
    'configured' => [['connection_database' => 'app_db'], 'app_db'],
    'configured with surrounding whitespace' => [['connection_database' => ' app_db '], 'app_db'],
]);

test('makeFromConfig passes connection_database to the postgres handler', function () {
    $config = new \App\Services\Backup\DTO\DatabaseConnectionConfig(
        databaseType: DatabaseType::POSTGRESQL,
        serverName: 'PG Server',
        host: 'pg.example.com',
        port: 5432,
        username: 'postgres',
        password: 'secret',
        extraConfig: ['connection_database' => 'app_db'],
    );

    $database = (new DatabaseProvider)->makeFromConfig($config, 'myapp', 'pg.example.com', 5432);

    expect((new ReflectionClass($database))->getProperty('config')->getValue($database))
        ->toHaveKey('connection_database', 'app_db');
});

test('makeFromConfig builds correct config for SQLite with SSH array', function () {
    $config = new \App\Services\Backup\DTO\DatabaseConnectionConfig(
        databaseType: DatabaseType::SQLITE,
        serverName: 'SQLite Server',
        host: '',
        port: 0,
        username: '',
        password: '',
        sshConfig: ['host' => 'ssh.example.com', 'port' => 22, 'username' => 'deploy'],
    );

    $provider = new DatabaseProvider;
    $database = $provider->makeFromConfig($config, '/data/app.db', '', 0);

    expect($database)->toBeInstanceOf(SqliteDatabase::class);
});

test('makeFromConfig builds correct config for MongoDB with source database', function () {
    $config = new \App\Services\Backup\DTO\DatabaseConnectionConfig(
        databaseType: DatabaseType::MONGODB,
        serverName: 'Mongo Server',
        host: 'mongo.local',
        port: 27017,
        username: 'admin',
        password: 'secret',
        extraConfig: ['auth_source' => 'myauth'],
    );

    $provider = new DatabaseProvider;
    $database = $provider->makeFromConfig($config, 'targetdb', 'mongo.local', 27017, 'sourcedb');

    $result = $database->restore('/tmp/dump.archive');
    expect($result->command)->toContain("--nsFrom='sourcedb.*'")
        ->toContain("--nsTo='targetdb.*'")
        ->toContain('authSource=myauth');
});

test('makeForServer builds an S3 handler from extra_config', function () {
    $server = DatabaseServer::factory()->create([
        'database_type' => 's3',
        'host' => 'minio.example.com',
        'port' => 9000,
        'username' => 'AKIAEXAMPLE',
        'password' => 'secretkey',
        'extra_config' => [
            's3_bucket' => 'photos',
            's3_region' => 'us-east-1',
            's3_use_path_style_endpoint' => true,
            's3_prefix' => 'hired',
        ],
    ]);

    $database = (new DatabaseProvider)->makeForServer($server, '', 'minio.example.com', 9000);

    expect($database)->toBeInstanceOf(S3Database::class);

    $config = (new ReflectionClass($database))->getProperty('config')->getValue($database);
    expect($config)->toMatchArray([
        'bucket' => 'photos',
        'region' => 'us-east-1',
        'access_key_id' => 'AKIAEXAMPLE',
        'secret_access_key' => 'secretkey',
        'use_path_style_endpoint' => true,
        'root' => 'hired',
    ])
        ->and($config['custom_endpoint'])->toBe('http://minio.example.com:9000');
});

test('makeFromConfig maps ssl to an https S3 custom endpoint', function () {
    $config = new \App\Services\Backup\DTO\DatabaseConnectionConfig(
        databaseType: DatabaseType::S3,
        serverName: 'B2 Server',
        host: 's3.us-west-004.backblazeb2.com',
        port: 443,
        username: 'b2-key',
        password: 'b2-secret',
        extraConfig: ['s3_bucket' => 'archives', 'ssl_enabled' => true],
    );

    $database = (new DatabaseProvider)->makeFromConfig($config, '', 's3.us-west-004.backblazeb2.com', 443);

    expect($database)->toBeInstanceOf(S3Database::class);

    $built = (new ReflectionClass($database))->getProperty('config')->getValue($database);
    expect($built['bucket'])->toBe('archives')
        ->and($built['custom_endpoint'])->toBe('https://s3.us-west-004.backblazeb2.com:443')
        ->and($built['region'])->toBe('us-east-1');
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
