<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Exceptions\Backup\ConnectionException;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Support\Formatters;

/**
 * Microsoft SQL Server handler.
 *
 * Backup and restore both go through Microsoft's `sqlpackage` CLI
 * (`/Action:Export` produces a `.bacpac`, `/Action:Import` consumes one).
 * Connection tests, listDatabases, and the drop-before-import step in
 * prepareForRestore use the `pdo_sqlsrv` extension.
 */
class MssqlDatabase implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    private const array EXCLUDED_DATABASES = [
        'master',
        'tempdb',
        'model',
        'msdb',
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
        $parts = [
            'sqlpackage',
            '/Action:Export',
            '/TargetFile:'.escapeshellarg($outputPath),
            '/SourceServerName:'.escapeshellarg($this->buildServerName()),
            '/SourceDatabaseName:'.escapeshellarg($this->config['database']),
            '/SourceUser:'.escapeshellarg($this->config['user']),
            '/SourcePassword:'.escapeshellarg($this->config['pass']),
            '/SourceTrustServerCertificate:True',
            '/SourceEncryptConnection:True',
        ];

        if (! empty($this->config['dump_flags'])) {
            $parts[] = DatabaseOperationResult::escapeFlags($this->config['dump_flags']);
        }

        return new DatabaseOperationResult(command: implode(' ', $parts));
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        // sqlpackage Import refuses any SourceFile that doesn't end in `.bacpac`.
        // RestoreTask hands us the decompressed file as `<workdir>/snapshot`, so
        // we rename it in place before invoking sqlpackage.
        $bacpacPath = str_ends_with($inputPath, '.bacpac') ? $inputPath : $inputPath.'.bacpac';

        $sqlpackage = implode(' ', [
            'sqlpackage',
            '/Action:Import',
            '/SourceFile:'.escapeshellarg($bacpacPath),
            '/TargetServerName:'.escapeshellarg($this->buildServerName()),
            '/TargetDatabaseName:'.escapeshellarg($this->config['database']),
            '/TargetUser:'.escapeshellarg($this->config['user']),
            '/TargetPassword:'.escapeshellarg($this->config['pass']),
            '/TargetTrustServerCertificate:True',
            '/TargetEncryptConnection:True',
        ]);

        if ($bacpacPath === $inputPath) {
            return new DatabaseOperationResult(command: $sqlpackage);
        }

        return new DatabaseOperationResult(command: sprintf(
            'mv %s %s && %s',
            escapeshellarg($inputPath),
            escapeshellarg($bacpacPath),
            $sqlpackage,
        ));
    }

    /**
     * sqlpackage Import refuses to run if the target database already exists,
     * so we drop it first. ALTER ... SET SINGLE_USER WITH ROLLBACK IMMEDIATE
     * evicts any lingering sessions before the DROP.
     */
    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        try {
            $sql = self::dropDatabaseIfExistsSql($schemaName);
            $logger->log("Ensuring target database {$schemaName} is dropped before sqlpackage Import", 'info');
            $logger->logCommand($sql, null, 0);
            $this->createPdoForDatabase('master')->exec($sql);
        } catch (\PDOException $e) {
            throw new ConnectionException("Failed to prepare database: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Build a T-SQL block that drops a database iff it currently exists,
     * evicting any active sessions first. Shared with integration test
     * helpers so the prod and test drop paths cannot drift apart.
     */
    public static function dropDatabaseIfExistsSql(string $name): string
    {
        $bracketed = '['.str_replace(']', ']]', $name).']';
        $quoted = "'".str_replace("'", "''", $name)."'";

        return "IF DB_ID({$quoted}) IS NOT NULL BEGIN "
            ."ALTER DATABASE {$bracketed} SET SINGLE_USER WITH ROLLBACK IMMEDIATE; "
            ."DROP DATABASE {$bracketed}; "
            .'END';
    }

    public function listDatabases(): array
    {
        $pdo = $this->createPdo();

        $statement = $pdo->query('SELECT name FROM sys.databases ORDER BY name');
        if ($statement === false) {
            throw new \RuntimeException('Failed to execute query: SELECT name FROM sys.databases');
        }

        $databases = $statement->fetchAll(\PDO::FETCH_COLUMN, 0);

        return array_values(array_filter(
            $databases,
            fn ($db): bool => ! in_array($db, self::EXCLUDED_DATABASES, true),
        ));
    }

    public function testConnection(): array
    {
        $startTime = microtime(true);

        try {
            $pdo = $this->createPdo();
            $statement = $pdo->query('SELECT @@VERSION');
            $version = $statement === false ? null : (string) $statement->fetchColumn();
        } catch (\PDOException $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($durationMs >= 9500) {
                return [
                    'success' => false,
                    'message' => 'Connection timed out after '.Formatters::humanDuration($durationMs).'. Please check the host and port are correct and accessible.',
                    'details' => [],
                ];
            }

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'details' => [],
            ];
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $shortVersion = $version !== null ? $this->extractShortVersion($version) : null;

        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => [
                'ping_ms' => $durationMs,
                'output' => json_encode([
                    'dbms' => $shortVersion ?? 'Microsoft SQL Server',
                    'version' => $version,
                ], JSON_PRETTY_PRINT),
            ],
        ];
    }

    /**
     * Create a PDO connection. Protected so tests can override it.
     */
    protected function createPdo(): \PDO
    {
        return $this->createPdoForDatabase($this->config['database'] ?? '');
    }

    protected function createPdoForDatabase(string $database): \PDO
    {
        $dsn = sprintf(
            'sqlsrv:Server=%s;TrustServerCertificate=true;Encrypt=true;LoginTimeout=10%s',
            $this->buildServerName(),
            $database !== '' ? ';Database='.$database : '',
        );

        return new \PDO($dsn, $this->config['user'], $this->config['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function buildServerName(): string
    {
        return sprintf('%s,%d', $this->config['host'], (int) $this->config['port']);
    }

    /**
     * Extract a short product name from the verbose `@@VERSION` string, e.g.
     * "Microsoft SQL Server 2022 (RTM)".
     */
    private function extractShortVersion(string $fullVersion): ?string
    {
        if (preg_match('/Microsoft SQL [^\r\n]*?\d{4}[^\r\n(]*/i', $fullVersion, $matches) === 1) {
            return trim($matches[0]);
        }

        if (preg_match('/Microsoft Azure SQL[^\r\n]*/i', $fullVersion, $matches) === 1) {
            return trim($matches[0]);
        }

        return null;
    }
}
