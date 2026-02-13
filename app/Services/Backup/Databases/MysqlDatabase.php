<?php

namespace App\Services\Backup\Databases;

use App\Exceptions\Backup\ConnectionException;
use App\Models\BackupJob;
use App\Services\Backup\Databases\DTO\DatabaseOperationResult;
use App\Support\Formatters;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

class MysqlDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    private const array DUMP_OPTIONS = [
        '--single-transaction', // Consistent snapshot for InnoDB without locking
        '--routines',           // Include stored procedures and functions
        '--add-drop-table',     // Add DROP TABLE before each CREATE TABLE
        '--complete-insert',    // Use complete INSERT statements with column names
        '--hex-blob',           // Encode binary data as hex for safer transport
        '--quote-names',        // Quote identifiers with backticks
    ];

    /** @var array<string, array<string, string>> */
    private const array CLI_BINARIES = [
        'mariadb' => [
            'dump' => 'mariadb-dump',
            'restore' => 'mariadb',
        ],
        'mysql' => [
            'dump' => 'mysqldump',
            'restore' => 'mysql',
        ],
    ];

    private const array EXCLUDED_DATABASES = [
        'information_schema',
        'performance_schema',
        'mysql',
        'sys',
    ];

    private function getMysqlCliType(): string
    {
        return config('backup.mysql_cli_type', 'mariadb');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        $options = self::DUMP_OPTIONS;

        if ($this->getMysqlCliType() === 'mariadb') {
            $options[] = '--skip_ssl';
        }

        return new DatabaseOperationResult(command: sprintf(
            '%s %s --host=%s --port=%s --user=%s --password=%s %s > %s',
            self::CLI_BINARIES[$this->getMysqlCliType()]['dump'],
            implode(' ', $options),
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['pass']),
            escapeshellarg($this->config['database']),
            escapeshellarg($outputPath)
        ));
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        $sslFlag = $this->getMysqlCliType() === 'mariadb' ? '--skip_ssl ' : '';

        return new DatabaseOperationResult(command: sprintf(
            '%s --host=%s --port=%s --user=%s --password=%s %s%s -e %s',
            self::CLI_BINARIES[$this->getMysqlCliType()]['restore'],
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['pass']),
            $sslFlag,
            escapeshellarg($this->config['database']),
            escapeshellarg('source '.$inputPath)
        ));
    }

    public function prepareForRestore(string $schemaName, BackupJob $job): void
    {
        try {
            $pdo = $this->createPdo();
            $schemaName = str_replace('`', '', $schemaName);

            $dropCommand = "DROP DATABASE IF EXISTS `{$schemaName}`";
            $job->logCommand($dropCommand, null, 0);
            $pdo->exec($dropCommand);

            $createCommand = "CREATE DATABASE `{$schemaName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            $job->logCommand($createCommand, null, 0);
            $pdo->exec($createCommand);
        } catch (\PDOException $e) {
            throw new ConnectionException("Failed to prepare database: {$e->getMessage()}", 0, $e);
        }
    }

    public function listDatabases(): array
    {
        $pdo = $this->createPdo();

        $statement = $pdo->query('SHOW DATABASES');
        if ($statement === false) {
            throw new \RuntimeException('Failed to execute query: SHOW DATABASES');
        }

        $databases = $statement->fetchAll(\PDO::FETCH_COLUMN, 0);

        return array_values(array_filter($databases, fn ($db) => ! in_array($db, self::EXCLUDED_DATABASES)));
    }

    public function testConnection(): array
    {
        $command = $this->getStatusCommand();
        $startTime = microtime(true);

        try {
            $result = Process::timeout(10)->run($command);
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

        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => [
                'ping_ms' => $durationMs,
                'output' => trim($result->output()),
            ],
        ];
    }

    protected function createPdo(): \PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%d', $this->config['host'], $this->config['port']);

        return new \PDO($dsn, $this->config['user'], $this->config['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 30,
        ]);
    }

    private function getStatusCommand(): string
    {
        $cli = self::CLI_BINARIES[$this->getMysqlCliType()]['restore'];
        $skipSsl = $this->getMysqlCliType() === 'mariadb' ? '--skip_ssl' : '';

        return sprintf(
            '%s --host=%s --port=%s --user=%s --password=%s %s -e %s',
            $cli,
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['pass']),
            $skipSsl,
            escapeshellarg('STATUS;')
        );
    }
}
