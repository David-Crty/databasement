<?php

use App\Services\Backup\Databases\FirebirdDatabase;
use App\Services\Backup\DTO\DatabaseOperationResult;
use Illuminate\Support\Facades\Process;

const FIREBIRD_TEST_DATABASE = '/data/main.fdb';

beforeEach(function () {
    $this->db = new FirebirdDatabase;
    $this->db->setConfig([
        'host' => 'fb.local',
        'port' => 3050,
        'user' => 'sysdba',
        'pass' => 'masterkey',
        'database' => FIREBIRD_TEST_DATABASE,
    ]);
});

test('dump builds gbak backup command', function () {
    $result = $this->db->dump('/tmp/backup.fbk');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("gbak -b -g -user 'sysdba' -password 'masterkey' 'fb.local/3050:".FIREBIRD_TEST_DATABASE."' '/tmp/backup.fbk'");
});

test('testConnection returns success when isql probe succeeds', function () {
    Process::fake([
        '*' => Process::result(output: 'Database: '.FIREBIRD_TEST_DATABASE),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details'])->toHaveKey('ping_ms')
        ->and($result['details']['output'])->toContain('Database: '.FIREBIRD_TEST_DATABASE);
});

test('testConnection returns failure when probe fails', function () {
    Process::fake([
        '*' => Process::result(exitCode: 1, errorOutput: 'I/O error during "open" operation'),
    ]);

    $result = $this->db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('I/O error during "open" operation');
});

test('listDatabases returns configured names', function () {
    $this->db->setConfig([
        'database_names' => [FIREBIRD_TEST_DATABASE, '/data/archive.fdb'],
    ]);

    expect($this->db->listDatabases())->toBe([FIREBIRD_TEST_DATABASE, '/data/archive.fdb']);
});

test('restore builds gbak restore command', function () {
    $result = $this->db->restore('/tmp/backup.fbk');

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBe("gbak -rep -user 'sysdba' -password 'masterkey' '/tmp/backup.fbk' 'fb.local/3050:".FIREBIRD_TEST_DATABASE."'");
});

test('prepareForRestore is a no-op', function () {
    $logger = Mockery::mock(\App\Contracts\BackupLogger::class);

    expect(fn () => $this->db->prepareForRestore(FIREBIRD_TEST_DATABASE, $logger))
        ->not->toThrow(Exception::class);
});
