<?php

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Services\Backup\Databases\DatabaseFactory;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Databases\RedisDatabase;
use App\Services\Backup\Databases\SqliteDatabase;

test('make returns correct handler for database type', function (DatabaseType $type, string $expectedClass) {
    $factory = new DatabaseFactory;

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

    $factory = new DatabaseFactory;

    // Simulate SSH tunnel override: pass different host/port than model
    $database = $factory->makeForServer($server, 'myapp', '127.0.0.1', 54321);

    $command = $database->getDumpCommandLine('/tmp/test.sql');
    expect($command)->toContain("--host='127.0.0.1'")
        ->toContain("--port='54321'")
        ->not->toContain('private-db.internal');
});
