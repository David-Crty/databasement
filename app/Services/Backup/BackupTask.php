<?php

namespace App\Services\Backup;

use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\FilesystemProvider;

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

    public function run(
        Snapshot $snapshot,
        string $workingDirectory = '/tmp'
    ): Snapshot {
        $databaseServer = $snapshot->databaseServer;
        $job = $snapshot->job;

        // Configure shell processor to log to job
        $this->setLogger($job);

        $workingFile = $workingDirectory.'/'.$snapshot->id.'.sql';

        try {
            // Mark as running
            $job->markRunning();

            // Use the database name from the snapshot (important for multi-database backups)
            $databaseName = $snapshot->database_name;

            $job->log("Starting backup for database: {$databaseName}", 'info', [
                'database_server' => [
                    'id' => $databaseServer->id,
                    'name' => $databaseServer->name,
                    'database_name' => $databaseName,
                    'database_type' => $databaseServer->database_type,
                ],
                'volume' => [
                    'id' => $snapshot->volume_id,
                    'type' => $snapshot->volume->type,
                ],
                'method' => $snapshot->method,
            ]);

            $this->dumpDatabase($databaseServer, $databaseName, $workingFile);
            $archive = $this->compressor->compress($workingFile);

            $job->log("Transferring backup to volume: {$snapshot->volume->name}", 'info', [
                'volume_type' => $snapshot->volume->type,
            ]);
            $destinationPath = $this->generateBackupFilename($databaseServer, $databaseName);
            $transferStart = microtime(true);
            $this->filesystemProvider->transfer(
                $snapshot->volume,
                $archive,
                $destinationPath
            );
            $transferDuration = round(microtime(true) - $transferStart, 1);
            $job->log('Transfer completed successfully in '.$transferDuration.'s', 'success', [
                'destination_path' => $destinationPath,
                'duration_seconds' => $transferDuration,
            ]);

            // Calculate file size and checksum
            $fileSize = filesize($archive);
            $checksum = hash_file('sha256', $archive);

            $job->log('Backup completed successfully', 'success', [
                'file_size' => $fileSize,
                'checksum' => substr($checksum, 0, 16).'...',
                'destination' => $destinationPath,
            ]);

            // Update snapshot with success
            $snapshot->update([
                'path' => $destinationPath,
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
        // Configure database interface with the specific database name
        $this->configureDatabaseInterface($databaseServer, $databaseName);

        $command = match ($databaseServer->database_type) {
            'mysql', 'mariadb' => $this->mysqlDatabase->getDumpCommandLine($outputPath),
            'postgresql' => $this->postgresqlDatabase->getDumpCommandLine($outputPath),
            default => throw new \Exception("Database type {$databaseServer->database_type} not supported"),
        };

        $this->shellProcessor->process($command);
    }

    private function generateBackupFilename(DatabaseServer $databaseServer, string $databaseName): string
    {
        $timestamp = now()->format('Y-m-d-His');
        $serverName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseServer->name);
        $sanitizedDbName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseName);

        return sprintf('%s-%s-%s.sql.gz', $serverName, $sanitizedDbName, $timestamp);
    }

    private function configureDatabaseInterface(DatabaseServer $databaseServer, string $databaseName): void
    {
        $config = [
            'host' => $databaseServer->host,
            'port' => $databaseServer->port,
            'user' => $databaseServer->username,
            'pass' => $databaseServer->password,
            'database' => $databaseName,
        ];

        match ($databaseServer->database_type) {
            'mysql', 'mariadb' => $this->mysqlDatabase->setConfig($config),
            'postgresql' => $this->postgresqlDatabase->setConfig($config),
            default => throw new \Exception("Database type {$databaseServer->database_type} not supported"),
        };
    }
}
