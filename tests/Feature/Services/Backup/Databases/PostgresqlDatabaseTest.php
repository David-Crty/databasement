<?php

use App\Services\Backup\Databases\PostgresqlDatabase;
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

test('getDumpCommandLine builds correct pg_dump command', function () {
    expect($this->db->getDumpCommandLine('/tmp/dump.sql'))
        ->toBe("PGPASSWORD='pg_secret' pg_dump --clean --if-exists --no-owner --no-privileges --quote-all-identifiers --host='pg.local' --port='5432' --username='postgres' 'myapp' -f '/tmp/dump.sql'");
});

test('getRestoreCommandLine builds correct psql command', function () {
    expect($this->db->getRestoreCommandLine('/tmp/restore.sql'))
        ->toBe("PGPASSWORD='pg_secret' psql --host='pg.local' --port='5432' --username='postgres' 'myapp' -f '/tmp/restore.sql'");
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
