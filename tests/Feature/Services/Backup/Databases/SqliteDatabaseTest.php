<?php

use App\Services\Backup\Databases\SqliteDatabase;

beforeEach(function () {
    $this->db = new SqliteDatabase;
    $this->db->setConfig(['sqlite_path' => '/data/app.sqlite']);
});

test('getDumpCommandLine produces cp command', function () {
    expect($this->db->getDumpCommandLine('/tmp/dump.db'))
        ->toBe("cp '/data/app.sqlite' '/tmp/dump.db'");
});

test('getRestoreCommandLine produces cp and chmod command', function () {
    expect($this->db->getRestoreCommandLine('/tmp/restore.db'))
        ->toBe("cp '/tmp/restore.db' '/data/app.sqlite' && chmod 0640 '/data/app.sqlite'");
});

test('prepareForRestore is a no-op', function () {
    $job = Mockery::mock(\App\Models\BackupJob::class);
    $job->shouldNotReceive('logCommand');

    $this->db->prepareForRestore('app.sqlite', $job);
});

test('testConnection returns success for valid SQLite file', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'sqlite_test_');

    $pdo = new PDO("sqlite:{$tempFile}");
    $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY)');
    $pdo = null;

    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => $tempFile]);

    $result = $db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details']['output'])->toContain('SQLite');

    unlink($tempFile);
});

test('testConnection returns error for empty path', function () {
    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => '']);

    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('required');
});

test('testConnection returns error for non-existent file', function () {
    $result = $this->db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('does not exist');
});

test('testConnection returns error for directory path', function () {
    $tempDir = sys_get_temp_dir().'/sqlite_test_dir_'.uniqid();
    mkdir($tempDir);

    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => $tempDir]);

    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('not a file');

    rmdir($tempDir);
});
