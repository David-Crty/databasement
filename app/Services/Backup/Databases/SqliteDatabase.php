<?php

namespace App\Services\Backup\Databases;

use App\Exceptions\Backup\DatabaseDumpException;
use App\Exceptions\Backup\RestoreException;
use App\Models\BackupJob;
use App\Services\Backup\Databases\DTO\DatabaseOperationLog;
use App\Services\Backup\Databases\DTO\DatabaseOperationResult;
use App\Services\Backup\Filesystems\SftpFilesystem;

class SqliteDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly SftpFilesystem $sftpFilesystem = new SftpFilesystem,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function listDatabases(): array
    {
        return [basename($this->config['sqlite_path'])];
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        $sourcePath = $this->config['sqlite_path'];
        $sshConfig = $this->config['ssh_config'] ?? null;

        if ($sshConfig !== null) {
            $filesystem = $this->sftpFilesystem->getFromSshConfig($sshConfig);
            $source = $filesystem->readStream($sourcePath);

            $dest = fopen($outputPath, 'wb');
            if ($dest === false) {
                fclose($source);
                throw new DatabaseDumpException("Failed to open destination for writing: {$outputPath}");
            }

            try {
                $bytes = stream_copy_to_stream($source, $dest);
                if ($bytes === false || $bytes === 0) {
                    throw new DatabaseDumpException("Failed to copy remote SQLite file {$sourcePath} to {$outputPath}");
                }
            } finally {
                fclose($source);
                fclose($dest);
            }

            return new DatabaseOperationResult(log: new DatabaseOperationLog(
                'Downloaded SQLite database via SFTP',
                'success',
                ['host' => $sshConfig->host, 'path' => $sourcePath],
            ));
        }

        if (! @copy($sourcePath, $outputPath)) {
            throw new DatabaseDumpException("Failed to copy local SQLite file {$sourcePath} to {$outputPath}");
        }

        return new DatabaseOperationResult(log: new DatabaseOperationLog(
            'Copied local SQLite database',
            'success',
            ['path' => $sourcePath],
        ));
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        $sshConfig = $this->config['ssh_config'] ?? null;

        if ($sshConfig !== null) {
            $filesystem = $this->sftpFilesystem->getFromSshConfig($sshConfig);
            $stream = fopen($inputPath, 'rb');
            if ($stream === false) {
                throw new RestoreException("Failed to open file for reading: {$inputPath}");
            }
            try {
                $filesystem->writeStream($this->config['sqlite_path'], $stream);
            } finally {
                fclose($stream);
            }

            return new DatabaseOperationResult(log: new DatabaseOperationLog(
                'Uploaded SQLite database via SFTP',
                'success',
                ['host' => $sshConfig->host, 'path' => $this->config['sqlite_path']],
            ));
        }

        $targetPath = $this->config['sqlite_path'];
        if (! @copy($inputPath, $targetPath)) {
            throw new RestoreException("Failed to copy SQLite file {$inputPath} to {$targetPath}");
        }
        chmod($targetPath, 0640);

        return new DatabaseOperationResult(log: new DatabaseOperationLog(
            'Restored local SQLite database',
            'success',
            ['path' => $this->config['sqlite_path']],
        ));
    }

    public function prepareForRestore(string $schemaName, BackupJob $job): void
    {
        // SQLite doesn't need database preparation — the file is replaced during restore
    }

    public function testConnection(): array
    {
        $path = $this->config['sqlite_path'] ?? '';

        if (empty($path)) {
            return ['success' => false, 'message' => 'Database path is required.', 'details' => []];
        }

        if (! file_exists($path)) {
            return ['success' => false, 'message' => 'Database file does not exist: '.$path, 'details' => []];
        }

        if (! is_readable($path)) {
            return ['success' => false, 'message' => 'Database file is not readable: '.$path, 'details' => []];
        }

        if (! is_file($path)) {
            return ['success' => false, 'message' => 'Path is not a file: '.$path, 'details' => []];
        }

        try {
            $pdo = new \PDO("sqlite:{$path}", null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->query('SELECT 1 FROM sqlite_master LIMIT 1');

            $stmt = $pdo->query('SELECT sqlite_version()');
            $version = $stmt ? $stmt->fetchColumn() : 'unknown';

            $fileSize = filesize($path);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => [
                    'output' => json_encode(['dbms' => "SQLite {$version}", 'file_size' => $fileSize, 'path' => $path], JSON_PRETTY_PRINT),
                ],
            ];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Invalid SQLite database file: '.$e->getMessage(), 'details' => []];
        }
    }
}
