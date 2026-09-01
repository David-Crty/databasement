<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Exceptions\Backup\ConnectionException;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Support\Formatters;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

class PostgresqlDatabase implements DatabaseInterface
{
    /**
     * Database opened when the server names none of its own. Conventional on a
     * self-hosted cluster; managed providers often withhold CONNECT on it.
     */
    public const string DEFAULT_CONNECTION_DATABASE = 'postgres';

    /** @var array<string, mixed> */
    private array $config;

    private const array DUMP_OPTIONS = [
        '--clean',                  // Add DROP statements before CREATE
        '--if-exists',              // Use IF EXISTS with DROP to avoid errors
        '--no-owner',               // Don't output ownership commands (more portable)
        '--no-privileges',          // Don't output GRANT/REVOKE (more portable)
        '--quote-all-identifiers',  // Quote all identifiers (safer for reserved words)
    ];

    /**
     * Restore-side flags applied by pg_restore when reading a custom-format archive.
     * Only used by the custom-format branch of restore() — plain format uses psql -f
     * which accepts none of these. --clean/--if-exists must be passed at restore time
     * (not dump time) for custom archives. --jobs=4 enables parallel restore, which is
     * the main reason custom format exists.
     */
    private const array RESTORE_CUSTOM_FORMAT_OPTIONS = [
        '--clean',
        '--if-exists',
        '--no-owner',
        '--no-privileges',
        '--jobs=4',
    ];

    /**
     * Dropped from dump/restore options when the server is configured to
     * preserve ownership and privilege information (dump_privileges).
     */
    private const array PORTABILITY_OPTIONS = [
        '--no-owner',
        '--no-privileges',
    ];

    private const array EXCLUDED_DATABASES = [
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

    /**
     * Environment prefix that forces TLS when the server is configured to use SSL.
     *
     * Emits `PGSSLMODE=require ` (encrypted, server certificate not verified),
     * consumed by every libpq-based tool below (pg_dump, pg_restore, psql). An
     * empty string leaves libpq at its default (`prefer`), which negotiates TLS
     * opportunistically but silently falls back to plaintext.
     */
    private function sslEnvPrefix(): string
    {
        return ! empty($this->config['ssl_enabled']) ? 'PGSSLMODE=require ' : '';
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        $options = $this->withPrivilegeOptions(self::DUMP_OPTIONS);
        if (($this->config['dump_format'] ?? 'plain') === 'custom') {
            $options[] = '--format=custom';
        }

        $extraFlags = '';
        if (! empty($this->config['dump_flags'])) {
            $extraFlags = ' '.DatabaseOperationResult::escapeFlags($this->config['dump_flags']);
        }

        // Flags must come before the database name (last positional argument)
        $command = sprintf(
            '%sPGPASSWORD=%s pg_dump %s --host=%s --port=%s --username=%s%s %s',
            $this->sslEnvPrefix(),
            escapeshellarg($this->config['pass']),
            implode(' ', $options),
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            $extraFlags,
            escapeshellarg($this->config['database']),
        );

        $command .= ' -f '.escapeshellarg($outputPath);

        return new DatabaseOperationResult(command: $command);
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        if (($this->config['dump_format'] ?? 'plain') === 'custom') {
            return new DatabaseOperationResult(command: sprintf(
                '%sPGPASSWORD=%s pg_restore %s --host=%s --port=%s --username=%s --dbname=%s %s',
                $this->sslEnvPrefix(),
                escapeshellarg($this->config['pass']),
                implode(' ', $this->withPrivilegeOptions(self::RESTORE_CUSTOM_FORMAT_OPTIONS)),
                escapeshellarg($this->config['host']),
                escapeshellarg((string) $this->config['port']),
                escapeshellarg($this->config['user']),
                escapeshellarg($this->config['database']),
                escapeshellarg($inputPath),
            ));
        }

        // -v ON_ERROR_STOP=1 makes psql abort and exit non-zero on the first
        // failed statement. Without it psql skips past errors and still exits 0,
        // so a restore that recreated nothing was reported as successful. The
        // dump is written with --clean --if-exists, so the DROP statements it
        // replays are not an error when the object is absent.
        return new DatabaseOperationResult(command: sprintf(
            '%sPGPASSWORD=%s psql --set=ON_ERROR_STOP=1 --host=%s --port=%s --username=%s %s -f %s',
            $this->sslEnvPrefix(),
            escapeshellarg($this->config['pass']),
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['database']),
            escapeshellarg($inputPath)
        ));
    }

    /**
     * Strip the --no-owner/--no-privileges portability flags when the
     * configuration asks to preserve ownership and privilege information.
     *
     * @param  array<string>  $options
     * @return array<string>
     */
    private function withPrivilegeOptions(array $options): array
    {
        if (empty($this->config['dump_privileges'])) {
            return $options;
        }

        return array_values(array_diff($options, self::PORTABILITY_OPTIONS));
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        // DROP DATABASE runs over the connection database, so it can never
        // target the database that connection is open on. Servers with a single
        // reachable database hit this when both point at it. Checked up front:
        // the failure is a configuration conflict, not a connection problem.
        if ($forceDatabase && $schemaName === $this->connectionDatabase()) {
            throw new ConnectionException(sprintf(
                'Cannot recreate database "%s": it is also the connection database this server connects through. '
                .'Point the connection database at another database, or restore without database recreation.',
                $schemaName,
            ));
        }

        try {
            $pdo = $this->createPdo();

            // Escape double quotes for safe use in quoted PostgreSQL identifiers
            $safeIdentifier = str_replace('"', '""', $schemaName);

            $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $stmt->execute([$schemaName]);
            $exists = $stmt->fetchColumn();

            if ($exists) {
                $logger->log('Database exists, terminating existing connections', 'info');

                $terminateCommand = 'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()';
                $logger->logCommand($terminateCommand, null, 0);
                $terminateStmt = $pdo->prepare($terminateCommand);
                $terminateStmt->execute([$schemaName]);

                if ($forceDatabase) {
                    $dropCommand = "DROP DATABASE IF EXISTS \"{$safeIdentifier}\"";
                    $logger->logCommand($dropCommand, null, 0);
                    $pdo->exec($dropCommand);

                    $createCommand = "CREATE DATABASE \"{$safeIdentifier}\"";
                    $logger->logCommand($createCommand, null, 0);
                    $pdo->exec($createCommand);
                }
            } else {
                $createCommand = "CREATE DATABASE \"{$safeIdentifier}\"";
                $logger->logCommand($createCommand, null, 0);
                $pdo->exec($createCommand);
            }
        } catch (\PDOException $e) {
            throw new ConnectionException("Failed to prepare database: {$e->getMessage()}", 0, $e);
        }
    }

    public function transferOwnership(string $schemaName, string $username, BackupLogger $logger): void
    {
        try {
            $safeUser = str_replace('"', '""', $username);
            $safeDb = str_replace('"', '""', $schemaName);
            $safeRestoreUser = str_replace('"', '""', $this->config['user']);

            // Transfer database ownership
            $adminPdo = $this->createPdo();
            $ownerCmd = "ALTER DATABASE \"{$safeDb}\" OWNER TO \"{$safeUser}\"";
            $logger->logCommand($ownerCmd, null, 0);
            $adminPdo->exec($ownerCmd);

            // Reassign all objects from the restore connection user to the target user.
            // Skip when users are the same (would be a no-op).
            if ($this->config['user'] !== $username) {
                $targetPdo = $this->createPdoForDatabase($schemaName);
                $reassignCmd = "REASSIGN OWNED BY \"{$safeRestoreUser}\" TO \"{$safeUser}\"";
                $logger->logCommand($reassignCmd, null, 0);
                $targetPdo->exec($reassignCmd);
            } else {
                $logger->log('Restore user and target user are the same, skipping object reassignment');
            }
        } catch (\PDOException $e) {
            throw new ConnectionException("Failed to transfer ownership: {$e->getMessage()}", 0, $e);
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

    /**
     * Admin connection, used for work that cannot run inside the target
     * database itself (enumerating databases, dropping and recreating one).
     * Defaults to `postgres` unless the server names another database it
     * can actually reach.
     */
    protected function createPdo(): \PDO
    {
        return $this->createPdoForDatabase($this->connectionDatabase());
    }

    private function connectionDatabase(): string
    {
        return self::resolveConnectionDatabase($this->config);
    }

    /**
     * The database a connection opens on, falling back to `postgres` when the
     * server names none. Whitespace-only input is treated as naming none: a
     * blank dbname reaches libpq as a database that cannot exist, so trimming
     * here keeps a stray space from turning into an opaque connection error.
     *
     * @param  array<string, mixed>  $config
     */
    public static function resolveConnectionDatabase(array $config): string
    {
        $configured = $config['connection_database'] ?? null;
        $configured = is_string($configured) ? trim($configured) : '';

        return $configured !== ''
            ? $configured
            : self::DEFAULT_CONNECTION_DATABASE;
    }

    protected function createPdoForDatabase(string $database): \PDO
    {
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->config['host'], $this->config['port'], $database);

        if (! empty($this->config['ssl_enabled'])) {
            // Force TLS without verifying the server certificate, matching the CLI's PGSSLMODE.
            $dsn .= ';sslmode=require';
        }

        $timeout = (int) ($this->config['connect_timeout'] ?? 30);
        $dsn .= ';connect_timeout='.$timeout;

        return new \PDO($dsn, $this->config['user'], $this->config['pass'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => $timeout,
        ]);
    }

    private function getQueryCommand(string $query): string
    {
        return sprintf(
            '%sPGPASSWORD=%s psql --host=%s --port=%s --user=%s %s -t -c %s',
            $this->sslEnvPrefix(),
            escapeshellarg($this->config['pass']),
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['database']),
            escapeshellarg($query)
        );
    }
}
