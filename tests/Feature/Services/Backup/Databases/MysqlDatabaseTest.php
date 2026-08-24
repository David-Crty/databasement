<?php

use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\DTO\DatabaseOperationResult;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->db = new MysqlDatabase;
    $this->db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
    ]);
});

test('dump builds correct command with skip_ssl by default', function () {
    $result = $this->db->dump('/tmp/dump.sql');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("mariadb-dump --single-transaction --routines --add-drop-table --hex-blob --quote-names --skip_ssl --host='db.local' --port='3306' --user='root' --password='secret' 'myapp' > '/tmp/dump.sql'");
});

test('dump uses ssl-verify-server-cert=0 when ssl_enabled is true', function () {
    $db = new MysqlDatabase;
    $db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
        'ssl_enabled' => true,
    ]);

    $result = $db->dump('/tmp/dump.sql');

    expect($result->command)
        ->toContain('--ssl --ssl-verify-server-cert=0')
        ->not->toContain('--skip_ssl');
});

test('dump includes extra dump flags', function () {
    $db = new MysqlDatabase;
    $db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
        'dump_flags' => '--no-tablespaces --column-statistics=0',
    ]);

    $result = $db->dump('/tmp/dump.sql');

    // Flags must appear before the database name (mariadb-dump treats post-db args as table names)
    expect($result->command)->toContain("'--no-tablespaces' '--column-statistics=0' 'myapp'")
        ->and($result->command)->toEndWith("> '/tmp/dump.sql'");
});

/** The same excluded tables, dumped from two different schemas on one server. */
function mysqlDatabaseExcluding(string $schema): MysqlDatabase
{
    $db = new MysqlDatabase;
    $db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => $schema,
        'excluded_tables' => ['web_api_log', 'web_service_log'],
    ]);

    return $db;
}

test('dump qualifies excluded tables with the schema being dumped', function (string $schema) {
    $result = mysqlDatabaseExcluding($schema)->dump('/tmp/dump.sql');

    // One --ignore-table per table, prefixed with this dump's schema, before the database name
    expect($result->command)->toContain("'--ignore-table={$schema}.web_api_log' '--ignore-table={$schema}.web_service_log' '{$schema}'")
        ->and($result->command)->toEndWith("> '/tmp/dump.sql'");
})->with(['datasoft', 'red']);

test('dump combines excluded tables with extra dump flags', function () {
    $db = new MysqlDatabase;
    $db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
        'dump_flags' => '--no-tablespaces',
        'excluded_tables' => ['audit_log'],
    ]);

    expect($db->dump('/tmp/dump.sql')->command)
        ->toContain("'--no-tablespaces' '--ignore-table=myapp.audit_log' 'myapp'");
});

test('dump adds no ignore-table flags when excluded tables are absent or empty', function (mixed $excluded) {
    $db = new MysqlDatabase;
    $db->setConfig(array_merge([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
    ], $excluded === 'absent' ? [] : ['excluded_tables' => $excluded]));

    expect($db->dump('/tmp/dump.sql')->command)->not->toContain('--ignore-table');
})->with([
    'absent' => 'absent',
    'empty list' => [[]],
    'null' => [null],
]);

/** A handler on a live server reporting $version, or an unreadable one for null. */
function mysqlDatabaseReportingVersion(?string $version): MysqlDatabase
{
    $pdo = Mockery::mock(PDO::class);

    if ($version === null) {
        $pdo->shouldReceive('query')->andThrow(new PDOException('server has gone away'));
    } else {
        $statement = Mockery::mock(\PDOStatement::class);
        $statement->shouldReceive('fetchColumn')->andReturn($version);
        $pdo->shouldReceive('query')->with('SELECT VERSION()')->andReturn($statement);
    }

    $db = Mockery::mock(MysqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createPdo')->andReturn($pdo);
    $db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
        'probe_server_version' => true,
    ]);

    return $db;
}

test('dump keeps --routines for servers the MariaDB client can dump routines from', function (?string $version) {
    $result = mysqlDatabaseReportingVersion($version)->dump('/tmp/dump.sql');

    expect($result->command)->toContain('--routines')
        ->and($result->log)->toBeNull();
})->with([
    'MariaDB inside its own package range' => ['11.4.12-MariaDB-ubu2404'],
    'MySQL on the pre-2026 scheme' => ['9.7.2'],
    'MySQL 8' => ['8.4.11'],
    'unreadable version' => [null],
]);

// MySQL 26.7 clears the client's >= 10.3 package gate, so --routines triggers
// SHOW PACKAGE STATUS and MySQL rejects it with a syntax error (#494).
test('dump drops --routines for MySQL versions that trip the MariaDB package check', function () {
    $result = mysqlDatabaseReportingVersion('26.7.0')->dump('/tmp/dump.sql');

    expect($result->command)->not->toContain('--routines')
        ->and($result->command)->toContain('mariadb-dump --single-transaction --add-drop-table')
        ->and($result->log?->level)->toBe('warning')
        ->and($result->log?->message)->toContain('26.7.0');
});

test('dump does not probe the server when the config is not for a live server', function () {
    $db = Mockery::mock(MysqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldNotReceive('createPdo');
    $db->setConfig(['host' => 'hostname', 'port' => 3306, 'user' => 'user', 'pass' => '***', 'database' => 'dbname']);

    expect($db->dump('/path/to/output')->command)->toContain('--routines');
});

test('restore builds correct command with skip_ssl by default', function () {
    $result = $this->db->restore('/tmp/restore.sql');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("mariadb --host='db.local' --port='3306' --user='root' --password='secret' --skip_ssl 'myapp' -e 'source /tmp/restore.sql'");
});

test('restore uses ssl-verify-server-cert=0 when ssl_enabled is true', function () {
    $db = new MysqlDatabase;
    $db->setConfig([
        'host' => 'db.local',
        'port' => 3306,
        'user' => 'root',
        'pass' => 'secret',
        'database' => 'myapp',
        'ssl_enabled' => true,
    ]);

    $result = $db->restore('/tmp/restore.sql');

    expect($result->command)
        ->toContain('--ssl --ssl-verify-server-cert=0')
        ->not->toContain('--skip_ssl');
});

test('testConnection returns success when process succeeds', function () {
    Process::fake([
        '*' => Process::result(output: 'Uptime: 12345'),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details'])->toHaveKey('ping_ms')
        ->and($result['details']['output'])->toBe('Uptime: 12345');
});

test('listDatabases returns databases excluding system databases', function () {
    $pdoStatement = Mockery::mock(\PDOStatement::class);
    $pdoStatement->shouldReceive('fetchAll')
        ->once()
        ->with(PDO::FETCH_COLUMN, 0)
        ->andReturn(['information_schema', 'performance_schema', 'mysql', 'sys', 'app_database', 'test_database']);

    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('query')
        ->once()
        ->with('SHOW DATABASES')
        ->andReturn($pdoStatement);

    $db = Mockery::mock(MysqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createPdo')->once()->andReturn($pdo);
    $db->setConfig(['host' => 'db.local', 'port' => 3306, 'user' => 'root', 'pass' => 'secret', 'database' => '']);

    $databases = $db->listDatabases();

    expect($databases)->toBe(['app_database', 'test_database']);
});

test('testConnection returns failure when process fails', function () {
    Process::fake([
        '*' => Process::result(exitCode: 1, errorOutput: 'Access denied for user'),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Access denied');
});
