<?php

use App\Services\Backup\Databases\TimescaledbDatabase;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Services\Backup\InMemoryBackupLogger;

/** A TimescaleDB handler wired to a fixed connection, with $overrides applied. */
function timescaledbHandler(array $overrides = []): TimescaledbDatabase
{
    $db = new TimescaledbDatabase;
    $db->setConfig([
        'host' => 'ts.local',
        'port' => 5432,
        'user' => 'postgres',
        'pass' => 'ts_secret',
        'database' => 'metrics',
        ...$overrides,
    ]);

    return $db;
}

// A hypertable dump is a plain PostgreSQL dump, so backups are taken with the
// inherited pg_dump command unchanged.
test('dump is taken with pg_dump, streaming to stdout, just like PostgreSQL', function () {
    $result = timescaledbHandler()->dump('/tmp/dump.sql');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("PGPASSWORD='ts_secret' pg_dump --clean --if-exists --no-owner --no-privileges --quote-all-identifiers --host='ts.local' --port='5432' --username='postgres' --dbname='metrics'")
        ->and($result->writesToStdout)->toBeTrue();
});

// timescaledb_pre_restore()/post_restore() toggle the restoring flag on the
// database, which a plain restore between them needs to rebuild hypertables.
test('plain restore is bracketed by the TimescaleDB pre/post restore calls', function () {
    $command = timescaledbHandler()->restore('/tmp/restore.sql')->command;

    expect($command)->toBe(
        "PGPASSWORD='ts_secret' psql --host='ts.local' --port='5432' --user='postgres' --dbname='metrics' -t -c 'SELECT public.timescaledb_pre_restore();'"
        ." && PGPASSWORD='ts_secret' psql --set=ON_ERROR_STOP=1 --host='ts.local' --port='5432' --username='postgres' --dbname='metrics' -f '/tmp/restore.sql'"
        ." && PGPASSWORD='ts_secret' psql --host='ts.local' --port='5432' --user='postgres' --dbname='metrics' -t -c 'SELECT public.timescaledb_post_restore();'"
    );
});

// The && chain stops a failed restore from reaching post_restore, which would
// re-enable triggers on a half-loaded database.
test('post_restore only runs when the restore succeeds', function () {
    $command = timescaledbHandler()->restore('/tmp/restore.sql')->command;

    expect(substr_count($command, ' && '))->toBe(2)
        ->and($command)->toContain("-f '/tmp/restore.sql' && PGPASSWORD='ts_secret' psql");
});

test('custom-format restore wraps pg_restore in the pre/post restore calls', function () {
    $command = timescaledbHandler(['dump_format' => 'custom'])->restore('/tmp/snapshot.dump')->command;

    expect($command)->toContain("-c 'SELECT public.timescaledb_pre_restore();' && PGPASSWORD='ts_secret' pg_restore")
        ->and($command)->toEndWith("-c 'SELECT public.timescaledb_post_restore();'")
        ->and($command)->toContain("pg_restore --clean --if-exists --no-owner --no-privileges --host='ts.local'");
});

test('SSL carries into the pre and post restore calls', function () {
    $command = timescaledbHandler(['ssl_enabled' => true])->restore('/tmp/restore.sql')->command;

    expect(substr_count($command, 'PGSSLMODE=require '))->toBe(3);
});

// The inherited PostgreSQL step (re)creates the database; this subclass then
// installs the extension so a later timescaledb_pre_restore() has it loaded.
test('prepareForRestore creates the TimescaleDB extension on the target database', function () {
    $existsStmt = Mockery::mock(PDOStatement::class);
    $existsStmt->shouldReceive('execute')->once();
    $existsStmt->shouldReceive('fetchColumn')->once()->andReturn(1);

    $terminateStmt = Mockery::mock(PDOStatement::class);
    $terminateStmt->shouldReceive('execute')->once();

    $adminPdo = Mockery::mock(PDO::class);
    $adminPdo->shouldReceive('prepare')->with('SELECT 1 FROM pg_database WHERE datname = ?')->andReturn($existsStmt);
    $adminPdo->shouldReceive('prepare')->with(Mockery::pattern('/pg_terminate_backend/'))->andReturn($terminateStmt);

    $extensionPdo = Mockery::mock(PDO::class);
    $extensionPdo->shouldReceive('exec')->once()->with('CREATE EXTENSION IF NOT EXISTS timescaledb');

    $db = Mockery::mock(TimescaledbDatabase::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $db->shouldReceive('createPdo')->andReturn($adminPdo);
    $db->shouldReceive('createPdoForDatabase')->with('metrics')->andReturn($extensionPdo);
    $db->setConfig([
        'host' => 'ts.local', 'port' => 5432, 'user' => 'postgres', 'pass' => 'ts_secret', 'database' => 'metrics',
    ]);

    $db->prepareForRestore('metrics', new InMemoryBackupLogger, forceDatabase: false);
});
