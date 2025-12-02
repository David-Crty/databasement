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

        // Configure database interface with server credentials
        $this->configureDatabaseInterface($databaseServer);

        try {
            // Mark as running
            $job->markRunning();
            $job->log("Starting backup for database: {$databaseServer->database_name}", 'info', [
                'database_server' => [
                    'id' => $databaseServer->id,
                    'name' => $databaseServer->name,
                    'database_name' => $databaseServer->database_name,
                    'database_type' => $databaseServer->database_type,
                ],
                'volume' => [
                    'id' => $snapshot->volume_id,
                    'type' => $snapshot->volume->type,
                ],
                'method' => $snapshot->method,
            ]);

            $this->dumpDatabase($databaseServer, $workingFile);
            $archive = $this->compressor->compress($workingFile);

            $job->log("Transferring backup to volume: {$snapshot->volume->name}", 'info', [
                'volume_type' => $snapshot->volume->type,
            ]);
            $destinationPath = $this->generateBackupFilename($databaseServer);
            $this->filesystemProvider->transfer(
                $snapshot->volume,
                $archive,
                $destinationPath
            );
            $job->log('Transfer completed successfully', 'success', [
                'destination_path' => $destinationPath,
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

    private function dumpDatabase(DatabaseServer $databaseServer, string $outputPath): void
    {
        $command = match ($databaseServer->database_type) {
            'mysql', 'mariadb' => $this->mysqlDatabase->getDumpCommandLine($outputPath),
            'postgresql' => $this->postgresqlDatabase->getDumpCommandLine($outputPath),
            default => throw new \Exception("Database type {$databaseServer->database_type} not supported"),
        };

        $this->shellProcessor->process($command);
    }

    private function generateBackupFilename(DatabaseServer $databaseServer): string
    {
        $timestamp = now()->format('Y-m-d-His');
        $serverName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseServer->name);
        $databaseName = preg_replace('/[^a-zA-Z0-9-_]/', '-', $databaseServer->database_name ?? 'db');

        return sprintf('%s-%s-%s.sql.gz', $serverName, $databaseName, $timestamp);
    }

    private function configureDatabaseInterface(DatabaseServer $databaseServer): void
    {
        $config = [
            'host' => $databaseServer->host,
            'port' => $databaseServer->port,
            'user' => $databaseServer->username,
            'pass' => $databaseServer->password,
            'database' => $databaseServer->database_name,
        ];

        match ($databaseServer->database_type) {
            'mysql', 'mariadb' => $this->mysqlDatabase->setConfig($config),
            'postgresql' => $this->postgresqlDatabase->setConfig($config),
            default => throw new \Exception("Database type {$databaseServer->database_type} not supported"),
        };
    }
}
