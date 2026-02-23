<?php

use App\Exceptions\Backup\DatabaseDumpException;
use App\Exceptions\Backup\RestoreException;
use App\Models\DatabaseServerSshConfig;
use App\Services\Backup\Databases\SqliteDatabase;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Services\Backup\Filesystems\SftpFilesystem;
use League\Flysystem\Filesystem;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/sqlite-db-test-'.uniqid();
    mkdir($this->tempDir, 0777, true);
});

test('listDatabases returns basename of sqlite path', function () {
    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => '/data/myapp.sqlite']);

    expect($db->listDatabases())->toBe(['myapp.sqlite']);
});

test('dump copies local file', function () {
    $sourceFile = $this->tempDir.'/source.sqlite';
    file_put_contents($sourceFile, 'SQLite data');

    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => $sourceFile]);

    $outputPath = $this->tempDir.'/dump.db';
    $result = $db->dump($outputPath);

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBeNull()
        ->and($result->log->message)->toBe('Copied local SQLite database')
        ->and($result->log->context)->toBe(['path' => $sourceFile])
        ->and(file_get_contents($outputPath))->toBe('SQLite data');
});

test('dump downloads remote file via SFTP', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create([
        'host' => 'remote.example.com',
    ]);

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, 'remote SQLite data');
    rewind($stream);

    $mockRemoteFs = Mockery::mock(Filesystem::class);
    $mockRemoteFs->shouldReceive('readStream')
        ->once()
        ->with('/data/remote.sqlite')
        ->andReturn($stream);

    $mockSftp = Mockery::mock(SftpFilesystem::class);
    $mockSftp->shouldReceive('getFromSshConfig')
        ->once()
        ->with(Mockery::on(fn ($config) => $config->host === 'remote.example.com'))
        ->andReturn($mockRemoteFs);

    $db = new SqliteDatabase($mockSftp);
    $db->setConfig(['sqlite_path' => '/data/remote.sqlite', 'ssh_config' => $sshConfig]);

    $outputPath = $this->tempDir.'/dump.db';
    $result = $db->dump($outputPath);

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBeNull()
        ->and($result->log->message)->toBe('Downloaded SQLite database via SFTP')
        ->and($result->log->context)->toBe(['host' => 'remote.example.com', 'path' => '/data/remote.sqlite'])
        ->and(file_get_contents($outputPath))->toBe('remote SQLite data');
});

test('dump throws on local copy failure', function () {
    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => '/nonexistent/source.sqlite']);

    $db->dump($this->tempDir.'/dump.db');
})->throws(DatabaseDumpException::class, 'Failed to copy local SQLite file /nonexistent/source.sqlite');

test('dump throws when remote stream copy returns zero bytes', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create([
        'host' => 'remote.example.com',
    ]);

    // Return an empty stream (0 bytes)
    $stream = fopen('php://memory', 'r+');

    $mockRemoteFs = Mockery::mock(Filesystem::class);
    $mockRemoteFs->shouldReceive('readStream')
        ->once()
        ->with('/data/remote.sqlite')
        ->andReturn($stream);

    $mockSftp = Mockery::mock(SftpFilesystem::class);
    $mockSftp->shouldReceive('getFromSshConfig')
        ->once()
        ->andReturn($mockRemoteFs);

    $db = new SqliteDatabase($mockSftp);
    $db->setConfig(['sqlite_path' => '/data/remote.sqlite', 'ssh_config' => $sshConfig]);

    $db->dump($this->tempDir.'/dump.db');
})->throws(DatabaseDumpException::class, 'Failed to copy remote SQLite file /data/remote.sqlite');

test('restore copies local file and sets permissions', function () {
    $targetFile = $this->tempDir.'/target.sqlite';
    $inputFile = $this->tempDir.'/input.db';
    file_put_contents($inputFile, 'restored data');

    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => $targetFile]);

    $result = $db->restore($inputFile);

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBeNull()
        ->and($result->log->message)->toBe('Restored local SQLite database')
        ->and(file_get_contents($targetFile))->toBe('restored data');
});

test('restore uploads remote file via SFTP', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create([
        'host' => 'remote.example.com',
    ]);

    $mockRemoteFs = Mockery::mock(Filesystem::class);
    $mockRemoteFs->shouldReceive('writeStream')
        ->once()
        ->with('/data/remote.sqlite', Mockery::type('resource'));

    $mockSftp = Mockery::mock(SftpFilesystem::class);
    $mockSftp->shouldReceive('getFromSshConfig')
        ->once()
        ->with(Mockery::on(fn ($config) => $config->host === 'remote.example.com'))
        ->andReturn($mockRemoteFs);

    $db = new SqliteDatabase($mockSftp);
    $db->setConfig(['sqlite_path' => '/data/remote.sqlite', 'ssh_config' => $sshConfig]);

    $inputFile = $this->tempDir.'/input.db';
    file_put_contents($inputFile, 'restored data');

    $result = $db->restore($inputFile);

    expect($result)->toBeInstanceOf(DatabaseOperationResult::class)
        ->and($result->command)->toBeNull()
        ->and($result->log->message)->toBe('Uploaded SQLite database via SFTP')
        ->and($result->log->context)->toBe(['host' => 'remote.example.com', 'path' => '/data/remote.sqlite']);
});

test('restore throws on local copy failure', function () {
    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => '/nonexistent/target.sqlite']);

    $inputFile = $this->tempDir.'/input.db';
    file_put_contents($inputFile, 'restored data');

    $db->restore($inputFile);
})->throws(RestoreException::class, 'Failed to copy SQLite file');

test('prepareForRestore is a no-op', function () {
    $logger = Mockery::mock(\App\Contracts\BackupLogger::class);
    $logger->shouldNotReceive('logCommand');

    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => '/data/app.sqlite']);
    $db->prepareForRestore('app.sqlite', $logger);
});

test('testConnection returns success for valid SQLite file', function () {
    $tempFile = $this->tempDir.'/test.sqlite';

    $pdo = new PDO("sqlite:{$tempFile}");
    $pdo->exec('CREATE TABLE test (id INTEGER PRIMARY KEY)');
    $pdo = null;

    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => $tempFile]);

    $result = $db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details']['output'])->toContain('SQLite');
});

test('testConnection returns error for empty path', function () {
    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => '']);

    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('required');
});

test('testConnection returns error for non-existent file', function () {
    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => '/data/app.sqlite']);

    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('does not exist');
});

test('testConnection returns error for directory path', function () {
    $tempDir = $this->tempDir.'/subdir';
    mkdir($tempDir);

    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_path' => $tempDir]);

    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('not a file');
});

test('testConnection returns error when sqlite_paths is empty', function () {
    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_paths' => []]);

    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Database file path is required.');
});

test('testConnection tests all local paths', function () {
    $path1 = $this->tempDir.'/db1.sqlite';
    $path2 = $this->tempDir.'/db2.sqlite';
    (new PDO("sqlite:{$path1}"))->exec('CREATE TABLE t1 (id INTEGER)');
    (new PDO("sqlite:{$path2}"))->exec('CREATE TABLE t2 (id INTEGER)');

    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_paths' => [$path1, $path2]]);

    $result = $db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful');

    $output = json_decode($result['details']['output'], true);
    expect($output)->toHaveCount(2)
        ->and($output[0]['path'])->toBe($path1)
        ->and($output[1]['path'])->toBe($path2);
});

test('testConnection reports failing local paths', function () {
    $validPath = $this->tempDir.'/valid.sqlite';
    (new PDO("sqlite:{$validPath}"))->exec('CREATE TABLE t1 (id INTEGER)');
    $missingPath = $this->tempDir.'/missing.sqlite';

    $db = new SqliteDatabase;
    $db->setConfig(['sqlite_paths' => [$validPath, $missingPath]]);

    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain($missingPath)
        ->and($result['message'])->not->toContain($validPath);
});

test('testConnection routes to SFTP when ssh_config is present', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create([
        'host' => 'bastion.example.com',
    ]);

    $db = new SqliteDatabase;
    $db->setConfig([
        'sqlite_paths' => ['/path/to/database.sqlite'],
        'ssh_config' => $sshConfig,
    ]);

    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('SFTP connection failed');
});

test('testConnection returns SFTP success when remote file exists', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create([
        'host' => 'bastion.example.com',
        'port' => 22,
        'username' => 'test',
    ]);

    $mockFilesystem = Mockery::mock(Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->with('/data/app.sqlite')->andReturn(true);
    $mockFilesystem->shouldReceive('fileSize')->with('/data/app.sqlite')->andReturn(4096);

    $mockSftp = Mockery::mock(SftpFilesystem::class);
    $mockSftp->shouldReceive('getFromSshConfig')->andReturn($mockFilesystem);

    $db = new SqliteDatabase($mockSftp);
    $db->setConfig([
        'sqlite_paths' => ['/data/app.sqlite'],
        'ssh_config' => $sshConfig,
    ]);

    $result = $db->testConnection();

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toBe('Connection successful')
        ->and($result['details']['sftp'])->toBeTrue()
        ->and($result['details']['ssh_host'])->toBe('bastion.example.com');
});

test('testConnection tests all SFTP paths and reports failures', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create([
        'host' => 'bastion.example.com',
        'port' => 22,
        'username' => 'test',
    ]);

    $mockFilesystem = Mockery::mock(Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->with('/data/app.sqlite')->andReturn(true);
    $mockFilesystem->shouldReceive('fileSize')->with('/data/app.sqlite')->andReturn(4096);
    $mockFilesystem->shouldReceive('fileExists')->with('/data/missing.sqlite')->andReturn(false);
    $mockFilesystem->shouldReceive('fileExists')->with('/data/other.sqlite')->andReturn(false);

    $mockSftp = Mockery::mock(SftpFilesystem::class);
    $mockSftp->shouldReceive('getFromSshConfig')->andReturn($mockFilesystem);

    $db = new SqliteDatabase($mockSftp);
    $db->setConfig([
        'sqlite_paths' => ['/data/app.sqlite', '/data/missing.sqlite', '/data/other.sqlite'],
        'ssh_config' => $sshConfig,
    ]);

    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('/data/missing.sqlite')
        ->and($result['message'])->toContain('/data/other.sqlite')
        ->and($result['message'])->not->toContain('/data/app.sqlite');
});

test('testConnection returns SFTP error when remote file is missing', function () {
    $sshConfig = DatabaseServerSshConfig::factory()->create([
        'host' => 'bastion.example.com',
        'port' => 22,
        'username' => 'test',
    ]);

    $mockFilesystem = Mockery::mock(Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->with('/data/missing.sqlite')->andReturn(false);

    $mockSftp = Mockery::mock(SftpFilesystem::class);
    $mockSftp->shouldReceive('getFromSshConfig')->andReturn($mockFilesystem);

    $db = new SqliteDatabase($mockSftp);
    $db->setConfig([
        'sqlite_paths' => ['/data/missing.sqlite'],
        'ssh_config' => $sshConfig,
    ]);

    $result = $db->testConnection();

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Remote file does not exist: /data/missing.sqlite');
});
