<?php

namespace App\Services\Backup;

use App\Contracts\JobInterface;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\DatabaseConnectionTester;
use PDO;
use PDOException;
use Symfony\Component\Process\Process;

class RestoreTask
{
    public function __construct(
        private readonly MysqlDatabase $mysqlDatabase,
        private readonly PostgresqlDatabase $postgresqlDatabase,
        private readonly ShellProcessor $shellProcessor,
        private readonly FilesystemProvider $filesystemProvider,
        private readonly GzipCompressor $compressor,
        private readonly DatabaseConnectionTester $connectionTester
    ) {}

    /**
     * Restore a snapshot to a target database server
     *
     * @throws \Exception
     */
    public function run(
        DatabaseServer $targetServer,
        Snapshot $snapshot,
        string $schemaName,
        string $workingDirectory = '/tmp',
        ?JobInterface $restore = null
    ): void {
        // Configure shell processor to log to restore if available
        if ($restore) {
            $this->shellProcessor->setLogger($restore);
        }

        // Validate compatibility
        if ($restore) {
            $restore->log('Validating database compatibility', 'info');
        }
        $this->validateCompatibility($targetServer, $snapshot);
        if ($restore) {
            $restore->log('Database types are compatible', 'success', [
                'source_type' => $snapshot->database_type,
                'target_type' => $targetServer->database_type,
            ]);
        }

        // Test connection to target server
        if ($restore) {
            $restore->log("Testing connection to target server: {$targetServer->name}", 'info');
        }
        $this->testConnection($targetServer);
        if ($restore) {
            $restore->log('Connection test successful', 'success');
        }

        $compressedFile = null;
        $workingFile = null;

        try {
            // Download snapshot from volume
            if ($restore) {
                $restore->log("Downloading snapshot from volume: {$snapshot->volume->name}", 'info', [
                    'snapshot_path' => $snapshot->path,
                    'volume_type' => $snapshot->volume->type,
                ]);
            }
            $compressedFile = $workingDirectory.'/'.basename($snapshot->path);
            $this->filesystemProvider->download($snapshot, $compressedFile);
            if ($restore) {
                $restore->log('Snapshot downloaded successfully', 'success', [
                    'file_size' => filesize($compressedFile),
                ]);
            }

            // Decompress the file
            if ($restore) {
                $restore->log('Decompressing snapshot file', 'info');
            }
            $workingFile = $this->decompress($compressedFile);
            if ($restore) {
                $restore->log('Decompression completed successfully', 'success', [
                    'decompressed_size' => filesize($workingFile),
                ]);
            }

            // Drop and recreate the database
            if ($restore) {
                $restore->log("Preparing target database: {$schemaName}", 'info');
            }
            $this->prepareDatabase($targetServer, $schemaName, $restore);
            if ($restore) {
                $restore->log('Database prepared successfully', 'success');
            }

            // Configure database interface with target server credentials
            $this->configureDatabaseInterface($targetServer, $schemaName);

            // Restore the database
            if ($restore) {
                $restore->log('Restoring database from snapshot', 'info', [
                    'source_database' => $snapshot->database_name,
                    'target_database' => $schemaName,
                ]);
            }
            $this->restoreDatabase($targetServer, $workingFile);
            if ($restore) {
                $restore->log('Database restore completed successfully', 'success');
            }
        } catch (\Throwable $e) {
            if ($restore) {
                $restore->log("Restore failed: {$e->getMessage()}", 'error', [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
            throw $e;
        } finally {
            // Clean up temporary files
            if ($restore) {
                $restore->log('Cleaning up temporary files', 'info');
            }
            if (isset($compressedFile) && file_exists($compressedFile)) {
                unlink($compressedFile);
            }
            if (file_exists($workingFile)) {
                unlink($workingFile);
            }
        }
    }

    private function validateCompatibility(DatabaseServer $targetServer, Snapshot $snapshot): void
    {
        if ($targetServer->database_type !== $snapshot->database_type) {
            throw new \Exception(
                "Cannot restore {$snapshot->database_type} snapshot to {$targetServer->database_type} server"
            );
        }
    }

    private function testConnection(DatabaseServer $targetServer): void
    {
        // For connection test, use existing database or default system database
        $testDatabase = match ($targetServer->database_type) {
            'mysql', 'mariadb' => $targetServer->database_name ?? 'mysql',
            'postgresql' => $targetServer->database_name ?? 'postgres',
            default => $targetServer->database_name,
        };

        $result = $this->connectionTester->test([
            'database_type' => $targetServer->database_type,
            'host' => $targetServer->host,
            'port' => $targetServer->port,
            'username' => $targetServer->username,
            'password' => $targetServer->password,
            'database_name' => $testDatabase,
        ]);

        if (! $result['success']) {
            throw new \Exception("Failed to connect to target server: {$result['message']}");
        }
    }

    private function decompress(string $compressedFile): string
    {
        // Copy the compressed file to a temporary location for decompression
        $tempCompressed = $compressedFile.'.tmp.gz';
        copy($compressedFile, $tempCompressed);

        $this->shellProcessor->process(
            Process::fromShellCommandline(
                $this->compressor->getDecompressCommandLine($tempCompressed)
            )
        );

        // After decompression, the file will be without .gz extension
        $decompressedFile = $this->compressor->getDecompressedPath($tempCompressed);

        if (! file_exists($decompressedFile)) {
            throw new \RuntimeException('Decompression failed: output file not found');
        }

        // Move to final location
        return $decompressedFile;
    }

    protected function prepareDatabase(DatabaseServer $targetServer, string $schemaName, ?JobInterface $restore = null): void
    {
        try {
            $pdo = $this->createConnection($targetServer);

            match ($targetServer->database_type) {
                'mysql', 'mariadb' => $this->prepareMysqlDatabase($pdo, $schemaName, $restore),
                'postgresql' => $this->preparePostgresqlDatabase($pdo, $schemaName, $restore),
                default => throw new \Exception("Database type {$targetServer->database_type} not supported"),
            };
        } catch (PDOException $e) {
            throw new \Exception("Failed to prepare database: {$e->getMessage()}", 0, $e);
        }
    }

    private function prepareMysqlDatabase(PDO $pdo, string $schemaName, ?JobInterface $restore = null): void
    {
        // Drop database if exists
        $dropCommand = "DROP DATABASE IF EXISTS `{$schemaName}`";
        if ($restore) {
            $restore->log('Dropping existing database if exists', 'info');
            $restore->logCommand($dropCommand, null, 0);
        }
        $pdo->exec($dropCommand);

        // Create new database
        $createCommand = "CREATE DATABASE `{$schemaName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        if ($restore) {
            $restore->log('Creating new database', 'info');
            $restore->logCommand($createCommand, null, 0);
        }
        $pdo->exec($createCommand);
    }

    private function preparePostgresqlDatabase(PDO $pdo, string $schemaName, ?JobInterface $restore = null): void
    {
        // Check if database exists
        $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $stmt->execute([$schemaName]);
        $exists = $stmt->fetchColumn();

        if ($exists) {
            if ($restore) {
                $restore->log('Database exists, terminating existing connections', 'info');
            }

            // Terminate existing connections to the database
            $terminateCommand = "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$schemaName}' AND pid <> pg_backend_pid()";
            if ($restore) {
                $restore->logCommand($terminateCommand, null, 0);
            }
            $pdo->exec($terminateCommand);

            // Drop the database
            $dropCommand = "DROP DATABASE IF EXISTS \"{$schemaName}\"";
            if ($restore) {
                $restore->log('Dropping existing database', 'info');
                $restore->logCommand($dropCommand, null, 0);
            }
            $pdo->exec($dropCommand);
        }

        // Create new database
        $createCommand = "CREATE DATABASE \"{$schemaName}\"";
        if ($restore) {
            $restore->log('Creating new database', 'info');
            $restore->logCommand($createCommand, null, 0);
        }
        $pdo->exec($createCommand);
    }

    private function restoreDatabase(DatabaseServer $targetServer, string $inputPath): void
    {
        switch ($targetServer->database_type) {
            case 'mysql':
            case 'mariadb':
                $this->shellProcessor->process(
                    Process::fromShellCommandline(
                        $this->mysqlDatabase->getRestoreCommandLine($inputPath)
                    )
                );
                break;
            case 'postgresql':
                $this->shellProcessor->process(
                    Process::fromShellCommandline(
                        $this->postgresqlDatabase->getRestoreCommandLine($inputPath)
                    )
                );
                break;
            default:
                throw new \Exception("Database type {$targetServer->database_type} not supported");
        }
    }

    private function configureDatabaseInterface(DatabaseServer $targetServer, string $schemaName): void
    {
        $config = [
            'host' => $targetServer->host,
            'port' => $targetServer->port,
            'user' => $targetServer->username,
            'pass' => $targetServer->password,
            'database' => $schemaName,
        ];

        match ($targetServer->database_type) {
            'mysql', 'mariadb' => $this->mysqlDatabase->setConfig($config),
            'postgresql' => $this->postgresqlDatabase->setConfig($config),
            default => throw new \Exception("Database type {$targetServer->database_type} not supported"),
        };
    }

    private function createConnection(DatabaseServer $targetServer): PDO
    {
        $dsn = $this->buildDsn($targetServer);

        return new PDO(
            $dsn,
            $targetServer->username,
            $targetServer->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 30,
            ]
        );
    }

    private function buildDsn(DatabaseServer $targetServer): string
    {
        return match ($targetServer->database_type) {
            'mysql', 'mariadb' => sprintf(
                'mysql:host=%s;port=%d',
                $targetServer->host,
                $targetServer->port
            ),
            'postgresql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=postgres',
                $targetServer->host,
                $targetServer->port
            ),
            default => throw new \Exception("Database type {$targetServer->database_type} not supported"),
        };
    }
}
