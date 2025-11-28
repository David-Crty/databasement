<?php

namespace App\Services\Backup;

use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\FilesystemProvider;
use Symfony\Component\Process\Process;

class BackupTask
{
    public function __construct(
        private readonly MysqlDatabase $mysqlDatabase,
        private readonly PostgresqlDatabase $postgresqlDatabase,
        private readonly ShellProcessor $shellProcessor,
        private readonly FilesystemProvider $filesystemProvider,
        private readonly GzipCompressor $compressor,
        private readonly DatabaseSizeCalculator $databaseSizeCalculator
    ) {}

    public function run(
        DatabaseServer $databaseServer,
        string $workingDirectory = '/tmp',
        string $method = 'manual',
        ?string $userId = null
    ): Snapshot {
        // Create snapshot record
        $snapshot = $this->createSnapshot($databaseServer, $method, $userId);

        // Configure shell processor to log to snapshot
        $this->shellProcessor->setLogger($snapshot);

        $workingFile = $workingDirectory.'/'.$snapshot->id.'.sql';
        $filesystem = $this->filesystemProvider->get($databaseServer->backup->volume->type);

        // Configure database interface with server credentials
        $this->configureDatabaseInterface($databaseServer);

        try {
            // Mark as running
            $snapshot->update(['status' => 'running']);
            $snapshot->log("Starting backup for database: {$databaseServer->database_name}", 'info', [
                'server' => $databaseServer->name,
                'database' => $databaseServer->database_name,
                'database_type' => $databaseServer->database_type,
                'method' => $method,
            ]);

            // Execute backup
            $snapshot->log('Dumping database to temporary file', 'info');
            $this->dumpDatabase($databaseServer, $workingFile);
            $snapshot->log('Database dump completed successfully', 'success');

            $snapshot->log('Compressing backup file with gzip', 'info');
            $archive = $this->compress($workingFile);
            $snapshot->log('Compression completed successfully', 'success');

            $snapshot->log("Transferring backup to volume: {$databaseServer->backup->volume->name}", 'info', [
                'volume_type' => $databaseServer->backup->volume->type,
            ]);
            $destinationPath = $this->generateBackupFilename($databaseServer);
            $this->filesystemProvider->transfert(
                $databaseServer->backup->volume,
                $archive,
                $destinationPath
            );
            $snapshot->log('Transfer completed successfully', 'success', [
                'destination_path' => $destinationPath,
            ]);

            // Calculate file size and checksum
            $fileSize = filesize($archive);
            $checksum = hash_file('sha256', $archive);

            $snapshot->log('Calculating file metadata', 'info');
            $snapshot->log('Backup completed successfully', 'success', [
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

            $snapshot->markCompleted();

            return $snapshot;
        } catch (\Throwable $e) {
            $snapshot->log("Backup failed: {$e->getMessage()}", 'error', [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $snapshot->markFailed($e);
            throw $e;
        } finally {
            // Clean up temporary files
            $snapshot->log('Cleaning up temporary files', 'info');
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
        switch ($databaseServer->database_type) {
            case 'mysql':
            case 'mariadb':
                $this->shellProcessor->process(
                    Process::fromShellCommandline(
                        $this->mysqlDatabase->getDumpCommandLine($outputPath)
                    )
                );
                break;
            case 'postgresql':
                $this->shellProcessor->process(
                    Process::fromShellCommandline(
                        $this->postgresqlDatabase->getDumpCommandLine($outputPath)
                    )
                );
                break;
            default:
                throw new \Exception("Database type {$databaseServer->database_type} not supported");
        }
    }

    private function compress(string $path): string
    {
        $this->shellProcessor->process(
            Process::fromShellCommandline(
                $this->compressor->getCompressCommandLine($path)
            )
        );

        return $this->compressor->getCompressedPath($path);
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

    private function createSnapshot(DatabaseServer $databaseServer, string $method, ?string $userId): Snapshot
    {
        // Calculate database size
        $databaseSize = $this->databaseSizeCalculator->calculate($databaseServer);

        return Snapshot::create([
            'database_server_id' => $databaseServer->id,
            'backup_id' => $databaseServer->backup->id,
            'volume_id' => $databaseServer->backup->volume_id,
            'path' => '', // Will be updated after transfer
            'file_size' => 0, // Will be updated after transfer
            'checksum' => null, // Will be updated after transfer
            'started_at' => now(),
            'completed_at' => null,
            'status' => 'pending',
            'error_message' => null,
            'error_trace' => null,
            'database_name' => $databaseServer->database_name ?? '',
            'database_type' => $databaseServer->database_type,
            'database_host' => $databaseServer->host,
            'database_port' => $databaseServer->port,
            'database_size_bytes' => $databaseSize,
            'compression_type' => 'gzip',
            'method' => $method,
            'triggered_by_user_id' => $userId,
        ]);
    }
}
