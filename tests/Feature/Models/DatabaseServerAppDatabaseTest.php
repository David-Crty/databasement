<?php

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;

// isAppDatabase() guards a restore from overwriting Databasement's own database.
// A TimescaleDB server still speaks to a PostgreSQL application database, so the
// pgsql driver has to match it exactly as it matches a plain PostgreSQL server.
// The application connection's keys are overridden in place (never the default
// connection name) so the test transaction RefreshDatabase opened stays intact.
function appDatabaseServer(DatabaseType $type, string $driver): DatabaseServer
{
    $connection = config('database.default');

    config([
        "database.connections.{$connection}.driver" => $driver,
        "database.connections.{$connection}.host" => 'db.internal',
        "database.connections.{$connection}.port" => 5432,
        "database.connections.{$connection}.database" => 'databasement',
    ]);

    $server = new DatabaseServer;
    $server->database_type = $type;
    $server->host = 'db.internal';
    $server->port = 5432;

    return $server;
}

test('a TimescaleDB server on the application PostgreSQL database is detected', function () {
    expect(appDatabaseServer(DatabaseType::TIMESCALEDB, 'pgsql')->isAppDatabase('databasement'))->toBeTrue();
});

test('a plain PostgreSQL server on the application database is still detected', function () {
    expect(appDatabaseServer(DatabaseType::POSTGRESQL, 'pgsql')->isAppDatabase('databasement'))->toBeTrue();
});

test('a TimescaleDB server is not the application database when the app runs on MySQL', function () {
    expect(appDatabaseServer(DatabaseType::TIMESCALEDB, 'mysql')->isAppDatabase('databasement'))->toBeFalse();
});

test('a TimescaleDB server pointed at another schema is not the application database', function () {
    expect(appDatabaseServer(DatabaseType::TIMESCALEDB, 'pgsql')->isAppDatabase('other_db'))->toBeFalse();
});
