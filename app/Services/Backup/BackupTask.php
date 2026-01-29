<?php

namespace App\Services\Backup;

use App\Enums\DatabaseType;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Support\FilesystemSupport;
use App\Support\Formatters;

class BackupTask
{
    public function __construct(
        private readonly MysqlDatabase $mysqlDatabase,
        private readonly PostgresqlDatabase $postgresqlDatabase,
        private readonly ShellProcessor $shellProcessor,
        private readonly FilesystemProvider $filesystemProvider,
        private readonly CompressorFactory $compressorFactory
    ) {}

    public function setLogger(BackupJob $job): void
    {
        $this->shellProcessor->setLogger($job);
    }

    public function run(Snapshot $snapshot, ?string $workingDirectory = null): Snapshot
    {
        $databaseServer = $snapshot->databaseServer;
        $job = $snapshot->job;
        $isSqlite = $databaseServer->database_type === DatabaseType::SQLITE;

        // Create compressor based on config (gzip, zstd, or encrypted)
        $compressor = $this->compressorFactory->make();

        // Configure shell processor to log to job
        $this->setLogger($job);
        try {
            if (! $workingDirectory) {
                $workingDirectory = FilesystemSupport::createWorkingDirectory('backup', $snapshot->id);
            }
            $extension = $isSqlite ? 'db' : 'sql';
            $workingFile = $workingDirectory.'/dump.'.$extension;

            $job->markRunning();

            // Use the database name from the snapshot (important for multi-database backups)
            $databaseName = $snapshot->database_name;

            $job->log("Starting backup for database: {$databaseName}", 'info');

            $this->dumpDatabase($databaseServer, $databaseName, $workingFile);
            $archive = $compressor->compress($workingFile);
            $fileSize = filesize($archive);
            if ($fileSize === false) {
                throw new \RuntimeException("Failed to get file size for: {$archive}");
            }
            $humanFileSize = Formatters::humanFileSize($fileSize);
            $filename = $this->generateFilename($databaseServer, $databaseName, $compressor);
            $job->log("Transferring backup ({$humanFileSize}) to volume: {$snapshot->volume->name}", 'info', [
                'volume_type' => $snapshot->volume->type,
                'source' => $archive,
                'destination' => $filename,
            ]);
            $transferStart = microtime(true);
            $this->filesystemProvider->transfer(
                $snapshot->volume,
                $archive,
                $filename
            );
            $transferDuration = Formatters::humanDuration((int) round((microtime(true) - $transferStart) * 1000));
            $job->log('Transfer completed successfully in '.$transferDuration, 'success');

            $checksum = hash_file('sha256', $archive);
            if ($checksum === false) {
                throw new \RuntimeException("Failed to calculate checksum for: {$archive}");
            }

            $job->log('Backup completed successfully', 'success', [
                'file_size' => $humanFileSize,
                'checksum' => substr($checksum, 0, 16).'...',
                'filename' => $filename,
            ]);

            // Update snapshot with success
            $snapshot->update([
                'filename' => $filename,
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
            // Clean up working directory and all files within (safety net, Job also cleans up on failure)
            $job->log('Cleaning up temporary files', 'info');
            if (is_dir($workingDirectory)) {
                FilesystemSupport::cleanupDirectory($workingDirectory);
            }
        }
    }

    private function dumpDatabase(DatabaseServer $databaseServer, string $databaseName, string $outputPath): void
    {
        if ($databaseServer->database_type === DatabaseType::SQLITE) {
            // SQLite: copy the file directly
            $command = $this->copySqliteDatabase($databaseServer->sqlite_path, $outputPath);
        } else {
            // Configure database interface with the specific database name
            $this->configureDatabaseInterface($databaseServer, $databaseName);

            $command = match ($databaseServer->database_type) {
                DatabaseType::MYSQL => $this->mysqlDatabase->getDumpCommandLine($outputPath),
                DatabaseType::POSTGRESQL => $this->postgresqlDatabase->getDumpCommandLine($outputPath),
                default => throw new \Exception("Database type {$databaseServer->database_type->value} not supported"),
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

    /**
     * Generate the filename to store in the volume.
     * Includes optional path prefix for organizing backups.
     */
    private function generateFilename(DatabaseServer $databaseServer, string $databaseName, CompressorInterface $compressor): string
    {
        $timestamp = now()->format('Y-m-d-His');
        $serverName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseServer->name);
        $sanitizedDbName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseName);
        $baseExtension = $databaseServer->database_type === DatabaseType::SQLITE ? 'db' : 'sql';
        $compressionExtension = $compressor->getExtension();

        $filename = sprintf('%s-%s-%s.%s.%s', $serverName, $sanitizedDbName, $timestamp, $baseExtension, $compressionExtension);

        // Prepend path if configured
        $path = $databaseServer->backup?->path;
        if (! empty($path)) {
            $path = trim($path, '/');
            $filename = $path.'/'.$filename;
        }

        return $filename;
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
            DatabaseType::MYSQL => $this->mysqlDatabase->setConfig($config),
            DatabaseType::POSTGRESQL => $this->postgresqlDatabase->setConfig($config),
            default => throw new \Exception("Database type {$databaseServer->database_type->value} not supported"),
        };
    }
}
