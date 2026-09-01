<?php

use App\Exceptions\Backup\ConnectionException;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Services\Backup\InMemoryBackupLogger;
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

test('dump keeps ownership and privileges when dump_privileges is enabled', function () {
    $db = new PostgresqlDatabase;
    $db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'pg_secret',
        'database' => 'myapp',
        'dump_privileges' => true,
    ]);

    $result = $db->dump('/tmp/dump.sql');

    expect($result->command)->toBe("PGPASSWORD='pg_secret' pg_dump --clean --if-exists --quote-all-identifiers --host='pg.local' --port='5432' --username='postgres' 'myapp' -f '/tmp/dump.sql'");
});

test('custom format restore keeps ownership and privileges when dump_privileges is enabled', function () {
    $db = new PostgresqlDatabase;
    $db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'pg_secret',
        'database' => 'myapp',
        'dump_format' => 'custom',
        'dump_privileges' => true,
    ]);

    $result = $db->restore('/tmp/snapshot.sql');

    expect($result->command)->toBe(
        "PGPASSWORD='pg_secret' pg_restore --clean --if-exists --jobs=4 --host='pg.local' --port='5432' --username='postgres' --dbname='myapp' '/tmp/snapshot.sql'"
    );
});

test('restore falls back to psql when dump_format is absent', function () {
    $result = $this->db->restore('/tmp/snapshot.sql');

    expect($result->command)->toStartWith("PGPASSWORD='pg_secret' psql ")
        ->and($result->command)->toContain('-f ');
});

test('dump prefixes PGSSLMODE=require when ssl_enabled is true', function () {
    $db = new PostgresqlDatabase;
    $db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'pg_secret',
        'database' => 'myapp',
        'ssl_enabled' => true,
    ]);

    $result = $db->dump('/tmp/dump.sql');

    expect($result->command)->toStartWith("PGSSLMODE=require PGPASSWORD='pg_secret' pg_dump ");
});

test('plain restore prefixes PGSSLMODE=require when ssl_enabled is true', function () {
    $db = new PostgresqlDatabase;
    $db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'pg_secret',
        'database' => 'myapp',
        'ssl_enabled' => true,
    ]);

    $result = $db->restore('/tmp/restore.sql');

    expect($result->command)->toStartWith("PGSSLMODE=require PGPASSWORD='pg_secret' psql ");
});

test('custom-format restore prefixes PGSSLMODE=require when ssl_enabled is true', function () {
    $db = new PostgresqlDatabase;
    $db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'pg_secret',
        'database' => 'myapp',
        'dump_format' => 'custom',
        'ssl_enabled' => true,
    ]);

    $result = $db->restore('/tmp/snapshot.sql');

    expect($result->command)->toStartWith("PGSSLMODE=require PGPASSWORD='pg_secret' pg_restore ");
});

test('dump and restore omit PGSSLMODE when ssl_enabled is absent', function () {
    expect($this->db->dump('/tmp/dump.sql')->command)->not->toContain('PGSSLMODE')
        ->and($this->db->restore('/tmp/restore.sql')->command)->not->toContain('PGSSLMODE');
});

test('testConnection query command carries PGSSLMODE=require when ssl_enabled is true', function () {
    Process::preventStrayProcesses();
    Process::fake([
        'PGSSLMODE=require*version*' => Process::result(output: 'PostgreSQL 16.2'),
        'PGSSLMODE=require*ssl*' => Process::result(output: 'yes'),
    ]);

    $db = new PostgresqlDatabase;
    $db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'pg_secret',
        'database' => 'myapp',
        'ssl_enabled' => true,
    ]);

    $result = $db->testConnection();

    expect($result['success'])->toBeTrue();
    Process::assertRan(fn ($process) => str_starts_with($process->command, 'PGSSLMODE=require PGPASSWORD='));
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

test('dump uses bare pg_dump by default', function () {
    expect($this->db->dump('/tmp/dump.sql')->command)->toContain(' pg_dump ');
});

test('dump uses the configured client bin dir when set', function () {
    config(['backup.postgresql_client_bin_dir' => '/usr/pgsql-18/bin']);

    expect($this->db->dump('/tmp/dump.sql')->command)->toContain(' /usr/pgsql-18/bin/pg_dump ')
        ->and($this->db->dump('/tmp/dump.sql')->command)->not->toContain(' pg_dump ');
});

test('restore uses the configured client bin dir for psql when set', function () {
    config(['backup.postgresql_client_bin_dir' => '/usr/pgsql-18/bin']);

    expect($this->db->restore('/tmp/restore.sql')->command)->toContain(' /usr/pgsql-18/bin/psql ');
});

test('restore uses the configured client bin dir for pg_restore when set', function () {
    config(['backup.postgresql_client_bin_dir' => '/usr/pgsql-18/bin']);

    $db = new PostgresqlDatabase;
    $db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'pg_secret',
        'database' => 'myapp',
        'dump_format' => 'custom',
    ]);

    expect($db->restore('/tmp/snapshot.sql')->command)->toContain(' /usr/pgsql-18/bin/pg_restore ');
});

test('a trailing slash on the configured bin dir does not produce a double slash', function () {
    config(['backup.postgresql_client_bin_dir' => '/usr/pgsql-18/bin/']);

    expect($this->db->dump('/tmp/dump.sql')->command)->toContain(' /usr/pgsql-18/bin/pg_dump ')
        ->and($this->db->dump('/tmp/dump.sql')->command)->not->toContain('bin//pg_dump');
});

test('testConnection query command uses the configured client bin dir for psql when set', function () {
    config(['backup.postgresql_client_bin_dir' => '/usr/pgsql-18/bin']);

    Process::preventStrayProcesses();
    Process::fake([
        '*version*' => Process::result(output: 'PostgreSQL 16.2'),
        '*ssl*' => Process::result(output: 'yes'),
    ]);

    $this->db->testConnection();

    Process::assertRan(fn ($process) => str_contains($process->command, '/usr/pgsql-18/bin/psql'));
});

test('testConnection returns failure when process fails', function () {
    Process::fake([
        '*' => Process::result(exitCode: 1, errorOutput: 'connection refused'),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('connection refused');
});

test('admin connections use the configured connection database', function (?string $configured, string $expected) {
    $db = new class extends PostgresqlDatabase
    {
        public string $connectedTo = '';

        protected function createPdoForDatabase(string $database): PDO
        {
            $this->connectedTo = $database;

            throw new PDOException('connection stubbed');
        }
    };

    $db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'app',
        'pass' => 'pg_secret',
        // The target database, deliberately different from the connection one:
        // listDatabases() must not connect here.
        'database' => 'myapp',
        'connection_database' => $configured,
    ]);

    expect(fn () => $db->listDatabases())->toThrow(PDOException::class)
        ->and($db->connectedTo)->toBe($expected);
})->with([
    'configured' => ['app_db', 'app_db'],
    'empty falls back' => ['', 'postgres'],
    'null falls back' => [null, 'postgres'],
]);

test('prepareForRestore refuses to recreate the connection database', function () {
    $db = new PostgresqlDatabase;
    $db->setConfig([
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'app',
        'pass' => 'pg_secret',
        'database' => 'app_db',
        'connection_database' => 'app_db',
    ]);

    // No connection is opened: the conflict is decided before createPdo().
    expect(fn () => $db->prepareForRestore('app_db', new InMemoryBackupLogger, forceDatabase: true))
        ->toThrow(ConnectionException::class, 'it is also the connection database');
});

test('prepareForRestore allows recreating a database other than the connection one', function () {
    $db = new PostgresqlDatabase;
    $db->setConfig([
        'host' => '127.0.0.1',
        'port' => 1,
        'user' => 'app',
        'pass' => 'pg_secret',
        'database' => 'other_db',
        'connection_database' => 'app_db',
    ]);

    // Passes the guard and fails later, on the connection itself.
    expect(fn () => $db->prepareForRestore('other_db', new InMemoryBackupLogger, forceDatabase: true))
        ->toThrow(ConnectionException::class, 'Failed to prepare database');
});
