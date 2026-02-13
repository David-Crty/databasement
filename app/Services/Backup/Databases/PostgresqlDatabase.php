<?php

namespace App\Services\Backup\Databases;

use App\Exceptions\Backup\ConnectionException;
use App\Models\BackupJob;
use App\Services\Backup\Databases\DTO\DatabaseOperationResult;
use App\Support\Formatters;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

class PostgresqlDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    private const array DUMP_OPTIONS = [
        '--clean',                  // Add DROP statements before CREATE
        '--if-exists',              // Use IF EXISTS with DROP to avoid errors
        '--no-owner',               // Don't output ownership commands (more portable)
        '--no-privileges',          // Don't output GRANT/REVOKE (more portable)
        '--quote-all-identifiers',  // Quote all identifiers (safer for reserved words)
    ];

    private const array EXCLUDED_DATABASES = [
        'postgres',          // Default administrative database
        'rdsadmin',          // AWS RDS internal database
        'azure_maintenance', // Azure Database for PostgreSQL internal database
        'azure_sys',         // Azure Database for PostgreSQL internal database
    ];

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        return new DatabaseOperationResult(command: sprintf(
            'PGPASSWORD=%s pg_dump %s --host=%s --port=%s --username=%s %s -f %s',
            escapeshellarg($this->config['pass']),
            implode(' ', self::DUMP_OPTIONS),
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['database']),
            escapeshellarg($outputPath)
        ));
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        return new DatabaseOperationResult(command: sprintf(
            'PGPASSWORD=%s psql --host=%s --port=%s --username=%s %s -f %s',
            escapeshellarg($this->config['pass']),
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['database']),
            escapeshellarg($inputPath)
        ));
    }

    public function prepareForRestore(string $schemaName, BackupJob $job): void
    {
        try {
            $pdo = $this->createPdo();

            // Escape double quotes for safe use in quoted PostgreSQL identifiers
            $safeIdentifier = str_replace('"', '""', $schemaName);

            $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $stmt->execute([$schemaName]);
            $exists = $stmt->fetchColumn();

            if ($exists) {
                $job->log('Database exists, terminating existing connections', 'info');

                $terminateCommand = 'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()';
                $job->logCommand($terminateCommand, null, 0);
                $terminateStmt = $pdo->prepare($terminateCommand);
                $terminateStmt->execute([$schemaName]);

                $dropCommand = "DROP DATABASE IF EXISTS \"{$safeIdentifier}\"";
                $job->logCommand($dropCommand, null, 0);
                $pdo->exec($dropCommand);
            }

            $createCommand = "CREATE DATABASE \"{$safeIdentifier}\"";
            $job->logCommand($createCommand, null, 0);
            $pdo->exec($createCommand);
        } catch (\PDOException $e) {
            throw new ConnectionException("Failed to prepare database: {$e->getMessage()}", 0, $e);
        }
    }

    public function listDatabases(): array
    {
        $pdo = $this->createPdo();

        $statement = $pdo->query('SELECT datname FROM pg_database WHERE datistemplate = false');
        if ($statement === false) {
            throw new \RuntimeException('Failed to execute query: SELECT datname FROM pg_database');
        }

        $databases = $statement->fetchAll(\PDO::FETCH_COLUMN, 0);

        return array_values(array_filter($databases, fn ($db) => ! in_array($db, self::EXCLUDED_DATABASES)));
    }

    public function testConnection(): array
    {
        $versionCommand = $this->getQueryCommand('SELECT version();');
        $startTime = microtime(true);

        try {
            $result = Process::timeout(10)->run($versionCommand);
        } catch (ProcessTimedOutException) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            return [
                'success' => false,
                'message' => 'Connection timed out after '.Formatters::humanDuration($durationMs).'. Please check the host and port are correct and accessible.',
                'details' => [],
            ];
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        if ($result->failed()) {
            $errorOutput = trim($result->errorOutput() ?: $result->output());

            return [
                'success' => false,
                'message' => $errorOutput ?: 'Connection failed with exit code '.$result->exitCode(),
                'details' => [],
            ];
        }

        $version = trim($result->output());

        // Get SSL status (non-critical, ignore failures)
        $sslCommand = $this->getQueryCommand(
            "SELECT CASE WHEN ssl THEN 'yes' ELSE 'no' END FROM pg_stat_ssl WHERE pid = pg_backend_pid();"
        );

        try {
            $sslResult = Process::timeout(10)->run($sslCommand);
            $ssl = $sslResult->successful() ? trim($sslResult->output()) : 'unknown';
        } catch (ProcessTimedOutException) {
            $ssl = 'unknown';
        }

        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => [
                'ping_ms' => $durationMs,
                'output' => json_encode(['dbms' => $version, 'ssl' => $ssl], JSON_PRETTY_PRINT),
            ],
        ];
    }

    protected function createPdo(): \PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=postgres', $this->config['host'], $this->config['port']);

        return new \PDO($dsn, $this->config['user'], $this->config['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 30,
        ]);
    }

    private function getQueryCommand(string $query): string
    {
        return sprintf(
            'PGPASSWORD=%s psql --host=%s --port=%s --user=%s %s -t -c %s',
            escapeshellarg($this->config['pass']),
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['database']),
            escapeshellarg($query)
        );
    }
}
