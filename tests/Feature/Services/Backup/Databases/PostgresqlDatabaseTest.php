<?php

use App\Exceptions\Backup\ConnectionException;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Services\Backup\InMemoryBackupLogger;
use Illuminate\Support\Facades\Process;

/** The connection every handler in this file is built on, with $overrides applied. */
function postgresConfig(array $overrides = []): array
{
    return [
        'host' => 'pg.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'pg_secret',
        'database' => 'myapp',
        ...$overrides,
    ];
}

/** A handler wired to {@see postgresConfig()}. */
function postgresHandler(array $overrides = []): PostgresqlDatabase
{
    $db = new PostgresqlDatabase;
    $db->setConfig(postgresConfig($overrides));

    return $db;
}

beforeEach(function () {
    $this->db = postgresHandler();
});

test('dump builds correct pg_dump command', function () {
    $result = $this->db->dump('/tmp/dump.sql');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("PGPASSWORD='pg_secret' pg_dump --clean --if-exists --no-owner --no-privileges --quote-all-identifiers --host='pg.local' --port='5432' --username='postgres' 'myapp' -f '/tmp/dump.sql'");
});

test('dump includes extra dump flags', function () {
    $db = postgresHandler(['dump_flags' => '--exclude-table=large_logs']);

    $result = $db->dump('/tmp/dump.sql');

    // Flags must appear before the database name (last positional argument)
    expect($result->command)->toContain("'--exclude-table=large_logs' 'myapp'")
        ->and($result->command)->toEndWith("-f '/tmp/dump.sql'");
});

test('restore builds correct psql command', function () {
    $result = $this->db->restore('/tmp/restore.sql');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("PGPASSWORD='pg_secret' psql --set=ON_ERROR_STOP=1 --host='pg.local' --port='5432' --username='postgres' 'myapp' -f '/tmp/restore.sql'");
});

test('plain restore stops on the first SQL error instead of exiting successfully', function () {
    $result = $this->db->restore('/tmp/restore.sql');

    expect($result->command)->toContain('--set=ON_ERROR_STOP=1');
});

test('dump appends --format=custom when dump_format is custom', function () {
    $db = postgresHandler(['dump_format' => 'custom']);

    $result = $db->dump('/tmp/dump.sql');

    expect($result->command)->toContain('--quote-all-identifiers --format=custom --host=')
        ->and($result->command)->toEndWith("'myapp' -f '/tmp/dump.sql'");
});

test('restore uses pg_restore when dump_format config is custom', function () {
    $db = postgresHandler(['dump_format' => 'custom']);

    $result = $db->restore('/tmp/snapshot.sql');

    expect($result->command)->toBe(
        "PGPASSWORD='pg_secret' pg_restore --clean --if-exists --no-owner --no-privileges --jobs=4 --host='pg.local' --port='5432' --username='postgres' --dbname='myapp' '/tmp/snapshot.sql'"
    );
});

test('dump keeps ownership and privileges when dump_privileges is enabled', function () {
    $db = postgresHandler(['dump_privileges' => true]);

    $result = $db->dump('/tmp/dump.sql');

    expect($result->command)->toBe("PGPASSWORD='pg_secret' pg_dump --clean --if-exists --quote-all-identifiers --host='pg.local' --port='5432' --username='postgres' 'myapp' -f '/tmp/dump.sql'");
});

test('custom format restore keeps ownership and privileges when dump_privileges is enabled', function () {
    $db = postgresHandler(['dump_format' => 'custom', 'dump_privileges' => true]);

    $result = $db->restore('/tmp/snapshot.sql');

    expect($result->command)->toBe(
        "PGPASSWORD='pg_secret' pg_restore --clean --if-exists --jobs=4 --host='pg.local' --port='5432' --username='postgres' --dbname='myapp' '/tmp/snapshot.sql'"
    );
});

test('every client is prefixed with PGSSLMODE=require when ssl_enabled is true', function (array $overrides, string $method, string $binary) {
    $result = postgresHandler([...$overrides, 'ssl_enabled' => true])->{$method}('/tmp/snapshot.sql');

    expect($result->command)->toStartWith("PGSSLMODE=require PGPASSWORD='pg_secret' {$binary} ");
})->with([
    'dump' => [[], 'dump', 'pg_dump'],
    'plain restore' => [[], 'restore', 'psql'],
    'custom-format restore' => [['dump_format' => 'custom'], 'restore', 'pg_restore'],
]);

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

    $db = postgresHandler(['ssl_enabled' => true]);

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
    $db->setConfig(postgresConfig(['database' => 'postgres']));

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

    // The target database stays 'myapp', deliberately different from the
    // connection one: listDatabases() must not connect there.
    $db->setConfig(postgresConfig([
        'user' => 'app',
        'connection_database' => $configured,
    ]));

    expect(fn () => $db->listDatabases())->toThrow(PDOException::class)
        ->and($db->connectedTo)->toBe($expected);
})->with([
    'configured' => ['app_db', 'app_db'],
    'empty falls back' => ['', 'postgres'],
    'null falls back' => [null, 'postgres'],
]);

test('prepareForRestore refuses to recreate the connection database', function () {
    $db = postgresHandler([
        'user' => 'app',
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

/** A handler whose connections are the given mocks, configured to restore as `databasement`. */
function postgresHandlerConnectingWith(PDO $adminPdo, bool $dumpPrivileges): PostgresqlDatabase
{
    $db = Mockery::mock(PostgresqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createPdo')->once()->andReturn($adminPdo);
    $db->setConfig(postgresConfig([
        'user' => 'databasement',
        'database' => 'restored_db',
        'dump_privileges' => $dumpPrivileges,
    ]));

    return $db;
}

test('ownership transfer hands the database over and reassigns what a portable restore created', function () {
    $adminPdo = Mockery::mock(PDO::class);
    $adminPdo->shouldReceive('exec')->once()->with('ALTER DATABASE "restored_db" OWNER TO "webapp"');

    $targetPdo = Mockery::mock(PDO::class);
    $targetPdo->shouldReceive('exec')->once()->with('REASSIGN OWNED BY "databasement" TO "webapp"');

    $db = postgresHandlerConnectingWith($adminPdo, dumpPrivileges: false);
    $db->shouldReceive('createPdoForDatabase')->once()->with('restored_db')->andReturn($targetPdo);

    $db->transferOwnership('restored_db', 'webapp', new InMemoryBackupLogger);
});

// A privilege-preserving snapshot restores its objects under their original
// owners, so only the database itself is left to hand over: pg_dump never
// describes the database, whatever the snapshot preserved (#525).
test('ownership transfer leaves the restored objects alone for a snapshot that carries its own owners', function () {
    $adminPdo = Mockery::mock(PDO::class);
    $adminPdo->shouldReceive('exec')->once()->with('ALTER DATABASE "restored_db" OWNER TO "webapp"');

    $db = postgresHandlerConnectingWith($adminPdo, dumpPrivileges: true);
    $db->shouldNotReceive('createPdoForDatabase');

    $db->transferOwnership('restored_db', 'webapp', new InMemoryBackupLogger);
});

/**
 * A handler on a live server reporting $serverVersion, exactly as PDO hands it
 * back from ATTR_SERVER_VERSION. Null stands for a server that cannot be read.
 */
function postgresHandlerReportingVersion(?string $serverVersion, array $config = []): PostgresqlDatabase
{
    $pdo = Mockery::mock(PDO::class);

    if ($serverVersion === null) {
        $pdo->shouldReceive('getAttribute')->andThrow(new PDOException('server closed the connection unexpectedly'));
    } else {
        $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->andReturn($serverVersion);
    }

    $db = Mockery::mock(PostgresqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createPdo')->andReturn($pdo);
    $db->setConfig(postgresConfig(['probe_server_version' => true, ...$config]));

    return $db;
}

// pg_dump 17 and 18 emit `SET transaction_timeout = 0`, a PG17+ GUC, whatever
// the version of the server they read, so their output cannot be replayed into
// an older server — not even the one it came from (#588, #590).
test('a server below 17 is dumped and restored with the matching older client', function (string $method, string $binary) {
    $result = postgresHandlerReportingVersion('16.15 (Debian 16.15-1.pgdg13+2)')->{$method}('/tmp/snapshot.sql');

    expect($result->command)->toContain("/postgresql16/{$binary} ")
        ->and($result->command)->not->toContain("PGPASSWORD='pg_secret' {$binary} ");
})->with([
    'dump' => ['dump', 'pg_dump'],
    'restore' => ['restore', 'psql'],
])->skip(
    ! is_executable('/usr/libexec/postgresql16/pg_dump'),
    'no PostgreSQL 16 client build in this image',
);

test('a custom-format snapshot restores into a server below 17 with the matching older client', function () {
    $result = postgresHandlerReportingVersion('16.15', ['dump_format' => 'custom'])->restore('/tmp/snapshot.dump');

    expect($result->command)->toContain('/postgresql16/pg_restore ');
})->skip(
    ! is_executable('/usr/libexec/postgresql16/pg_restore'),
    'no PostgreSQL 16 client build in this image',
);

// A native install without the versioned package still has to dump, so an
// older server with no matching build anywhere falls back to the client on
// PATH rather than to a path that does not exist.
test('a server below 17 falls back to the client on PATH when no matching build is installed', function () {
    $db = postgresHandlerReportingVersion('16.15');
    $db->shouldReceive('clientBinDirs')->andReturn(['/nonexistent/postgresql%d']);

    expect($db->dump('/tmp/dump.sql')->command)->toContain("PGPASSWORD='pg_secret' pg_dump ")
        ->and($db->restore('/tmp/restore.sql')->command)->toContain("PGPASSWORD='pg_secret' psql ");
});

// Falling back is survivable, but it produces a snapshot the source server
// cannot take back, so the job log has to say so while the backup is running.
test('falling back to the client on PATH warns that the snapshot may not restore', function (string $method, array $config) {
    $db = postgresHandlerReportingVersion('16.15', $config);
    $db->shouldReceive('clientBinDirs')->andReturn(['/nonexistent/postgresql%d']);

    $log = $db->{$method}('/tmp/snapshot.sql')->log;

    expect($log?->level)->toBe('warning')
        ->and($log?->message)->toContain('No PostgreSQL 16 client is installed')
        ->and($log?->message)->toContain('PostgreSQL 16 server');
})->with([
    'dump' => ['dump', []],
    'custom-format restore' => ['restore', ['dump_format' => 'custom']],
]);

test('a server handled by the matching client is not warned about', function () {
    expect(postgresHandlerReportingVersion('16.15')->dump('/tmp/dump.sql')->log)->toBeNull()
        ->and(postgresHandlerReportingVersion('17.11')->dump('/tmp/dump.sql')->log)->toBeNull();
})->skip(
    ! is_executable('/usr/libexec/postgresql16/pg_dump'),
    'no PostgreSQL 16 client build in this image',
);

test('servers the default client can write for keep it', function (?string $serverVersion) {
    $db = postgresHandlerReportingVersion($serverVersion);

    expect($db->dump('/tmp/dump.sql')->command)->toContain("PGPASSWORD='pg_secret' pg_dump ")
        ->and($db->restore('/tmp/restore.sql')->command)->toContain("PGPASSWORD='pg_secret' psql ");
})->with([
    'PostgreSQL 17' => ['17.11 (Debian 17.11-1.pgdg13+2)'],
    'PostgreSQL 18' => ['18.6 (Debian 18.6-1.pgdg13+2)'],
    'unreadable version' => [null],
]);

// The major has to survive every shape a server states its version in: managed
// services report a bare `major.minor`, distro packages append their own build
// string, and a pre-release has no minor at all. Anything unreadable must fall
// back to the default client rather than to major 0, which would read as
// ancient and wrongly pick the legacy one.
test('the client follows the major whatever shape the reported version comes in', function (string $reported, string $expected) {
    expect(postgresHandlerReportingVersion($reported)->dump('/tmp/dump.sql')->command)->toContain($expected);
})->with([
    'Debian and Ubuntu pgdg build, 16' => ['16.15 (Debian 16.15-1.pgdg13+2)', '/postgresql16/pg_dump '],
    'Alpine build, 16' => ['16.15', '/postgresql16/pg_dump '],
    'Homebrew build, 16' => ['16.15 (Homebrew)', '/postgresql16/pg_dump '],
    'Amazon RDS and Aurora, 12' => ['12.7', '/postgresql16/pg_dump '],
    'legacy 9.x numbering' => ['9.6.24', '/postgresql16/pg_dump '],
    'managed service, 17' => ['17.11', "PGPASSWORD='pg_secret' pg_dump "],
    'Debian pgdg build, 18' => ['18.6 (Debian 18.6-1.pgdg13+2)', "PGPASSWORD='pg_secret' pg_dump "],
    'pre-release, 18' => ['18beta1', "PGPASSWORD='pg_secret' pg_dump "],
    'empty' => ['', "PGPASSWORD='pg_secret' pg_dump "],
    'unparseable' => ['not a version', "PGPASSWORD='pg_secret' pg_dump "],
])->skip(
    ! is_executable('/usr/libexec/postgresql16/pg_dump'),
    'no PostgreSQL 16 client build in this image',
);

test('a display-only config is never probed for its version', function () {
    $db = Mockery::mock(PostgresqlDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldNotReceive('createPdo');
    $db->setConfig(postgresConfig());

    expect($db->dump('/tmp/dump.sql')->command)->toContain("PGPASSWORD='pg_secret' pg_dump ");
});
