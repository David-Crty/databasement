<?php

use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\DTO\DatabaseOperationResult;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->db = new PostgresqlDatabase;
    $this->db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'pg_secret',
        'database' => 'myapp',
    ]);
});

test('dump builds correct pg_dump command', function () {
    $result = $this->db->dump('/tmp/dump.sql');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("PGPASSWORD='pg_secret' pg_dump --clean --if-exists --no-owner --no-privileges --quote-all-identifiers --host='pg.local' --port='5432' --username='postgres' 'myapp' -f '/tmp/dump.sql'");
});

test('dump includes extra dump flags', function () {
    $db = new PostgresqlDatabase;
    $db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'pg_secret',
        'database' => 'myapp',
        'dump_flags' => '--exclude-table=large_logs',
    ]);

    $result = $db->dump('/tmp/dump.sql');

    // Flags must appear before the database name (last positional argument)
    expect($result->command)->toContain("'--exclude-table=large_logs' 'myapp'")
        ->and($result->command)->toEndWith("-f '/tmp/dump.sql'");
});

test('restore builds correct psql command', function () {
    $result = $this->db->restore('/tmp/restore.sql');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("PGPASSWORD='pg_secret' psql --host='pg.local' --port='5432' --username='postgres' 'myapp' -f '/tmp/restore.sql'");
});

test('dump appends --format=custom when dump_format is custom', function () {
    $db = new PostgresqlDatabase;
    $db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'pg_secret',
        'database' => 'myapp',
        'dump_format' => 'custom',
    ]);

    $result = $db->dump('/tmp/dump.sql');

    expect($result->command)->toContain('--quote-all-identifiers --format=custom --host=')
        ->and($result->command)->toEndWith("'myapp' -f '/tmp/dump.sql'");
});

test('restore uses pg_restore when dump_format config is custom', function () {
    $db = new PostgresqlDatabase;
    $db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'pg_secret',
        'database' => 'myapp',
        'dump_format' => 'custom',
    ]);

    $result = $db->restore('/tmp/snapshot.sql');

    expect($result->command)->toBe(
        "PGPASSWORD='pg_secret' pg_restore --clean --if-exists --no-owner --no-privileges --jobs=4 --host='pg.local' --port='5432' --username='postgres' --dbname='myapp' '/tmp/snapshot.sql'"
    );
});

test('restore falls back to psql when dump_format is absent', function () {
    $result = $this->db->restore('/tmp/snapshot.sql');

    expect($result->command)->toStartWith("PGPASSWORD='pg_secret' psql ")
        ->and($result->command)->toContain('-f ');
});

test('listDatabases returns databases excluding managed-service internals but keeps postgres', function () {
    $pdoStatement = Mockery::mock(\PDOStatement::class);
    $pdoStatement->shouldReceive('fetchAll')
        ->once()
        ->with(PDO::FETCH_COLUMN, 0)
        ->andReturn(['postgres', 'rdsadmin', 'azure_maintenance', 'azure_sys', 'app_database', 'analytics_db']);

    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('query')
        ->once()
        ->with('SELECT datname FROM pg_database WHERE datistemplate = false')
        ->andReturn($pdoStatement);

    $db = Mockery::mock(PostgresqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createPdo')->once()->andReturn($pdo);
    $db->setConfig(['host' => 'pg.local', 'port' => 5432, 'user' => 'postgres', 'pass' => 'pg_secret', 'database' => 'postgres']);

    $databases = $db->listDatabases();

    expect($databases)->toBe(['postgres', 'app_database', 'analytics_db']);
});

test('testConnection returns success with version and SSL info', function () {
    Process::fake([
        '*version*' => Process::result(output: 'PostgreSQL 16.2'),
        '*ssl*' => Process::result(output: 'yes'),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details'])->toHaveKey('ping_ms')
        ->and($result['details']['output'])->toContain('PostgreSQL 16.2');
});

test('testConnection returns failure when process fails', function () {
    Process::fake([
        '*' => Process::result(exitCode: 1, errorOutput: 'connection refused'),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('connection refused');
});
