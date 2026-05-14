<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Exceptions\Backup\ConnectionException;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Support\Formatters;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

class MysqlDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    private const string DUMP_BINARY = 'mariadb-dump';

    private const string CLIENT_BINARY = 'mariadb';

    private const array DUMP_OPTIONS = [
        '--single-transaction', // Consistent snapshot for InnoDB without locking
        '--routines',           // Include stored procedures and functions
        '--add-drop-table',     // Add DROP TABLE before each CREATE TABLE
        '--complete-insert',    // Use complete INSERT statements with column names
        '--hex-blob',           // Encode binary data as hex for safer transport
        '--quote-names',        // Quote identifiers with backticks
    ];

    private const array EXCLUDED_DATABASES = [
        'information_schema',
        'performance_schema',
        'mysql',
        'sys',
    ];

    /**
     * Resolve the SSL-related CLI flag.
     *
     * - ssl_enabled = true  → '--ssl --ssl-verify-server-cert=0' (encrypted, no cert verification).
     *                          `--ssl-verify-server-cert=0` alone already triggers TLS, but the
     *                          explicit `--ssl` makes the intent clear in the dump-command preview.
     * - ssl_enabled = false → '--skip_ssl' (plaintext — mariadb client defaults to TLS,
     *                                       which fails against MySQL's self-signed certs)
     */
    private function getSslFlag(): string
    {
        return ! empty($this->config['ssl_enabled'])
            ? '--ssl --ssl-verify-server-cert=0'
            : '--skip_ssl';
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
        $options[] = $this->getSslFlag();

        $extraFlags = '';
        if (! empty($this->config['dump_flags'])) {
            $extraFlags = ' '.DatabaseOperationResult::escapeFlags($this->config['dump_flags']);
        }

        // Flags must come before the database name; mariadb-dump treats anything after it as table names
        $command = sprintf(
            '%s %s --host=%s --port=%s --user=%s --password=%s%s %s',
            self::DUMP_BINARY,
            implode(' ', $options),
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['pass']),
            $extraFlags,
            escapeshellarg($this->config['database']),
        );

        $command .= ' > '.escapeshellarg($outputPath);

        return new DatabaseOperationResult(command: $command);
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        return new DatabaseOperationResult(command: sprintf(
            '%s --host=%s --port=%s --user=%s --password=%s %s %s -e %s',
            self::CLIENT_BINARY,
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['pass']),
            $this->getSslFlag(),
            escapeshellarg($this->config['database']),
            escapeshellarg('source '.$inputPath)
        ));
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        try {
            $pdo = $this->createPdo();
            $schemaName = str_replace('`', '', $schemaName);

            if ($forceDatabase) {
                $dropCommand = "DROP DATABASE IF EXISTS `{$schemaName}`";
                $logger->logCommand($dropCommand, null, 0);
                $pdo->exec($dropCommand);
            }

            $createCommand = "CREATE DATABASE IF NOT EXISTS `{$schemaName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            $logger->logCommand($createCommand, null, 0);
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

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 30,
        ];

        if (! empty($this->config['ssl_enabled'])) {
            // Force TLS without verifying the server certificate. The empty CA path is
            // required to trigger TLS negotiation; without it PDO silently stays plaintext.
            $options[\PDO::MYSQL_ATTR_SSL_CA] = '';
            $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        return new \PDO($dsn, $this->config['user'], $this->config['pass'], $options);
    }

    private function getStatusCommand(): string
    {
        return sprintf(
            '%s --host=%s --port=%s --user=%s --password=%s %s -e %s',
            self::CLIENT_BINARY,
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['pass']),
            $this->getSslFlag(),
            escapeshellarg('STATUS;')
        );
    }
}
