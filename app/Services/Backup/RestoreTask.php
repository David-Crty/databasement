<?php

namespace App\Services\Backup;

use App\Enums\DatabaseType;
use App\Exceptions\Backup\ConnectionException;
use App\Exceptions\Backup\RestoreException;
use App\Exceptions\Backup\UnsupportedDatabaseTypeException;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\ConnectionFactory;
use PDO;
use PDOException;

class RestoreTask
{
    public function __construct(
        private readonly MysqlDatabase $mysqlDatabase,
        private readonly PostgresqlDatabase $postgresqlDatabase,
        private readonly ShellProcessor $shellProcessor,
        private readonly FilesystemProvider $filesystemProvider,
        private readonly GzipCompressor $compressor,
        private readonly ConnectionFactory $connectionFactory
    ) {}

    /**
     * Restore a snapshot to a target database server
     *
     * @throws \Exception
     */
    public function run(
        Restore $restore,
        string $workingDirectory = '/tmp'
    ): Restore {
        $targetServer = $restore->targetServer;
        $snapshot = $restore->snapshot;

        $this->validateCompatibility($targetServer, $snapshot);

        $job = $restore->job;

        // Configure shell processor to log to job
        $this->shellProcessor->setLogger($job);

        $workingFile = null;

        try {
            // Mark as running
            $job->markRunning();
            $job->log('Starting restore operation', 'info', [
                'target_database_server' => [
                    'id' => $targetServer->id,
                    'name' => $targetServer->name,
                    'database_name' => $restore->schema_name,
                    'database_type' => $targetServer->database_type,
                ],
                'snapshot' => [
                    'id' => $snapshot->id,
                    'database_name' => $snapshot->database_name,
                    'database_server' => [
                        'id' => $snapshot->databaseServer->id,
                        'name' => $snapshot->databaseServer->name,
                        'database_type' => $snapshot->databaseServer->database_type,
                    ],
                ],
            ]);

            // Download snapshot from volume
            $job->log("Downloading snapshot from volume: {$snapshot->volume->name}", 'info', [
                'storage_uri' => $snapshot->storage_uri,
                'volume_type' => $snapshot->volume->type,
            ]);
            $compressedFile = $workingDirectory.'/'.$snapshot->getFilename();
            $this->filesystemProvider->download($snapshot, $compressedFile);
            $job->log('Snapshot downloaded successfully', 'success', [
                'file_size' => filesize($compressedFile),
            ]);
            $workingFile = $this->compressor->decompress($compressedFile);

            if ($targetServer->database_type === 'sqlite') {
                // SQLite: simply copy the file to the target path
                $job->log('Restoring SQLite database', 'info', [
                    'source_database' => $snapshot->database_name,
                    'target_path' => $targetServer->sqlite_path,
                ]);
                $this->restoreSqliteDatabase($workingFile, $targetServer->sqlite_path);
            } else {
                $this->prepareDatabase($targetServer, $restore->schema_name, $job);
                $this->configureDatabaseInterface($targetServer, $restore->schema_name);
                $job->log('Restoring database from snapshot', 'info', [
                    'source_database' => $snapshot->database_name,
                    'target_database' => $restore->schema_name,
                ]);
                $this->restoreDatabase($targetServer, $workingFile);
            }

            // Clean up temporary files (compressed file already deleted by gzip -d)
            $job->log('Cleaning up temporary files', 'info');
            if (file_exists($workingFile)) {
                unlink($workingFile);
            }

            // Mark job as completed
            $job->markCompleted();

            return $restore;
        } catch (\Throwable $e) {
            $job->log("Restore failed: {$e->getMessage()}", 'error', [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $job->markFailed($e);
            throw $e;
        } finally {
            // Ensure cleanup happens even on failure
            if ($workingFile !== null && file_exists($workingFile)) {
                unlink($workingFile);
            }
        }
    }

    private function validateCompatibility(DatabaseServer $targetServer, Snapshot $snapshot): void
    {
        if ($targetServer->database_type !== $snapshot->database_type) {
            throw new RestoreException(
                "Cannot restore {$snapshot->database_type} snapshot to {$targetServer->database_type} server"
            );
        }
    }

    protected function prepareDatabase(DatabaseServer $targetServer, string $schemaName, BackupJob $job): void
    {
        try {
            $pdo = $this->connectionFactory->createAdminConnection($targetServer);
            $databaseType = DatabaseType::from($targetServer->database_type);

            if ($databaseType->isMysqlFamily()) {
                $this->prepareMysqlDatabase($pdo, $schemaName, $job);
            } elseif ($databaseType === DatabaseType::POSTGRESQL) {
                $this->preparePostgresqlDatabase($pdo, $schemaName, $job);
            } else {
                throw new UnsupportedDatabaseTypeException($targetServer->database_type);
            }
        } catch (PDOException $e) {
            throw new ConnectionException("Failed to prepare database: {$e->getMessage()}", 0, $e);
        }
    }

    private function prepareMysqlDatabase(PDO $pdo, string $schemaName, BackupJob $job): void
    {
        // Drop database if exists
        $dropCommand = "DROP DATABASE IF EXISTS `{$schemaName}`";
        $job->logCommand($dropCommand, null, 0);
        $pdo->exec($dropCommand);

        // Create new database
        $createCommand = "CREATE DATABASE `{$schemaName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $job->logCommand($createCommand, null, 0);
        $pdo->exec($createCommand);
    }

    private function preparePostgresqlDatabase(PDO $pdo, string $schemaName, BackupJob $job): void
    {
        // Check if database exists
        $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $stmt->execute([$schemaName]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            $job->log('Database exists, terminating existing connections', 'info');

            // Terminate existing connections to the database
            $terminateCommand = "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$schemaName}' AND pid <> pg_backend_pid()";
            $job->logCommand($terminateCommand, null, 0);
            $pdo->exec($terminateCommand);

            // Drop the database
            $dropCommand = "DROP DATABASE IF EXISTS \"{$schemaName}\"";
            $job->logCommand($dropCommand, null, 0);
            $pdo->exec($dropCommand);
        }

        // Create new database
        $createCommand = "CREATE DATABASE \"{$schemaName}\"";
        $job->logCommand($createCommand, null, 0);
        $pdo->exec($createCommand);
    }

    private function restoreDatabase(DatabaseServer $targetServer, string $inputPath): void
    {
        $databaseType = DatabaseType::from($targetServer->database_type);

        $command = match (true) {
            $databaseType->isMysqlFamily() => $this->mysqlDatabase->getRestoreCommandLine($inputPath),
            $databaseType === DatabaseType::POSTGRESQL => $this->postgresqlDatabase->getRestoreCommandLine($inputPath),
            default => throw new UnsupportedDatabaseTypeException($targetServer->database_type),
        };

        $this->shellProcessor->process($command);
    }

    /**
     * Restore SQLite database by copying file to target path.
     */
    private function restoreSqliteDatabase(string $sourcePath, string $targetPath): void
    {
        $command = sprintf("cp '%s' '%s'", $sourcePath, $targetPath);
        $this->shellProcessor->process($command);
    }

    private function configureDatabaseInterface(DatabaseServer $targetServer, string $schemaName): void
    {
        $config = [
            'host' => $targetServer->host,
            'port' => $targetServer->port,
            'user' => $targetServer->username,
            'pass' => $targetServer->getDecryptedPassword(),
            'database' => $schemaName,
        ];

        $databaseType = DatabaseType::from($targetServer->database_type);

        if ($databaseType->isMysqlFamily()) {
            $this->mysqlDatabase->setConfig($config);
        } elseif ($databaseType === DatabaseType::POSTGRESQL) {
            $this->postgresqlDatabase->setConfig($config);
        } else {
            throw new UnsupportedDatabaseTypeException($targetServer->database_type);
        }
    }
}
