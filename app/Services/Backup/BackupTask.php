<?php

namespace App\Services\Backup;

use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Models\Volume;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Support\Formatters;

class BackupTask
{
    public function __construct(
        private readonly MysqlDatabase $mysqlDatabase,
        private readonly PostgresqlDatabase $postgresqlDatabase,
        private readonly ShellProcessor $shellProcessor,
        private readonly FilesystemProvider $filesystemProvider,
        private readonly GzipCompressor $compressor
    ) {}

    public function setLogger(BackupJob $job): void
    {
        $this->shellProcessor->setLogger($job);
    }

    public function run(Snapshot $snapshot): Snapshot
    {
        $databaseServer = $snapshot->databaseServer;
        $job = $snapshot->job;

        // Configure shell processor to log to job
        $this->setLogger($job);

        $workingDirectory = rtrim(config('backup.tmp_folder'), '/');
        $isSqlite = $databaseServer->database_type === 'sqlite';
        $extension = $isSqlite ? 'db' : 'sql';
        $workingFile = $workingDirectory.'/'.$snapshot->id.'.'.$extension;

        try {
            if (! is_dir($workingDirectory)) {
                mkdir($workingDirectory, 0755, true);
            }

            // Mark as running
            $job->markRunning();

            // Use the database name from the snapshot (important for multi-database backups)
            $databaseName = $snapshot->database_name;

            $job->log("Starting backup for database: {$databaseName}", 'info');

            $this->dumpDatabase($databaseServer, $databaseName, $workingFile);
            $archive = $this->compressor->compress($workingFile);
            $fileSize = filesize($archive);
            if ($fileSize === false) {
                throw new \RuntimeException("Failed to get file size for: {$archive}");
            }
            $humanFileSize = Formatters::humanFileSize($fileSize);
            $job->log("Transferring backup ({$humanFileSize}) to volume: {$snapshot->volume->name}", 'info', [
                'volume_type' => $snapshot->volume->type,
            ]);
            $filename = $this->generateBackupFilename($databaseServer, $databaseName);
            $transferStart = microtime(true);
            $this->filesystemProvider->transfer(
                $snapshot->volume,
                $archive,
                $filename
            );
            $transferDuration = Formatters::humanDuration((int) round((microtime(true) - $transferStart) * 1000));

            // Build the storage URI based on volume type
            $storageUri = $this->buildStorageUri($snapshot->volume, $filename);

            $job->log('Transfer completed successfully in '.$transferDuration, 'success', [
                'storage_uri' => $storageUri,
                'duration' => $transferDuration,
            ]);

            $checksum = hash_file('sha256', $archive);
            if ($checksum === false) {
                throw new \RuntimeException("Failed to calculate checksum for: {$archive}");
            }

            $job->log('Backup completed successfully', 'success', [
                'file_size' => $humanFileSize,
                'checksum' => substr($checksum, 0, 16).'...',
                'storage_uri' => $storageUri,
            ]);

            // Update snapshot with success
            $snapshot->update([
                'storage_uri' => $storageUri,
                'file_size' => $fileSize,
                'checksum' => $checksum,
            ]);

            // Mark job as completed
            $job->markCompleted();

            return $snapshot;
        } catch (\Throwable $e) {
            $job->log("Backup failed: {$e->getMessage()}", 'error', [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $job->markFailed($e);

            throw $e;
        } finally {
            // Clean up temporary files
            $job->log('Cleaning up temporary files', 'info');
            if (file_exists($workingFile)) {
                unlink($workingFile);
            }
            if (isset($archive) && file_exists($archive)) {
                unlink($archive);
            }
        }
    }

    private function dumpDatabase(DatabaseServer $databaseServer, string $databaseName, string $outputPath): void
    {
        if ($databaseServer->database_type === 'sqlite') {
            // SQLite: copy the file directly
            $command = $this->copySqliteDatabase($databaseServer->sqlite_path, $outputPath);
        } else {
            // Configure database interface with the specific database name
            $this->configureDatabaseInterface($databaseServer, $databaseName);

            $command = match ($databaseServer->database_type) {
                'mysql', 'mariadb' => $this->mysqlDatabase->getDumpCommandLine($outputPath),
                'postgresql' => $this->postgresqlDatabase->getDumpCommandLine($outputPath),
                default => throw new \Exception("Database type {$databaseServer->database_type} not supported"),
            };
        }

        $this->shellProcessor->process($command);
    }

    /**
     * Copy SQLite database file to the output path.
     */
    private function copySqliteDatabase(string $sourcePath, string $outputPath): string
    {
        return sprintf("cp '%s' '%s'", $sourcePath, $outputPath);
    }

    private function generateBackupFilename(DatabaseServer $databaseServer, string $databaseName): string
    {
        $timestamp = now()->format('Y-m-d-His');
        $serverName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseServer->name);
        $sanitizedDbName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseName);
        $extension = $databaseServer->database_type === 'sqlite' ? 'db.gz' : 'sql.gz';

        return sprintf('%s-%s-%s.%s', $serverName, $sanitizedDbName, $timestamp, $extension);
    }

    /**
     * Build a storage URI for the given volume and filename
     */
    private function buildStorageUri(Volume $volume, string $filename): string
    {
        $config = $volume->config;

        if ($volume->type === 's3') {
            $bucket = $config['bucket'] ?? '';
            $prefix = $config['prefix'] ?? '';
            $path = $prefix ? rtrim($prefix, '/').'/'.$filename : $filename;

            return Snapshot::buildStorageUri('s3', $path, $bucket);
        }

        // Local filesystem
        $basePath = $config['path'] ?? '';
        $fullPath = rtrim($basePath, '/').'/'.$filename;

        return Snapshot::buildStorageUri('local', $fullPath);
    }

    private function configureDatabaseInterface(DatabaseServer $databaseServer, string $databaseName): void
    {
        $config = [
            'host' => $databaseServer->host,
            'port' => $databaseServer->port,
            'user' => $databaseServer->username,
            'pass' => $databaseServer->getDecryptedPassword(),
            'database' => $databaseName,
        ];

        match ($databaseServer->database_type) {
            'mysql', 'mariadb' => $this->mysqlDatabase->setConfig($config),
            'postgresql' => $this->postgresqlDatabase->setConfig($config),
            default => throw new \Exception("Database type {$databaseServer->database_type} not supported"),
        };
    }
}
