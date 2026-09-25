<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Enums\DatabaseType;
use App\Exceptions\Backup\ConnectionException;
use App\Services\Backup\DTO\DatabaseOperationLog;
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

    /**
     * Newest client major whose output an older server still accepts.
     *
     * pg_dump 17 and 18 write `SET transaction_timeout = 0` into every dump,
     * a GUC that only exists from PostgreSQL 17 on, and they do it whatever
     * the version of the server they read. A dump is only guaranteed to load
     * into a server at least as new as the client that wrote it, so servers
     * below 17 are dumped and restored with a client of this major instead.
     */
    private const int LEGACY_CLIENT_MAJOR = 16;

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
            $extraFlags = ' '.DatabaseOperationResult::escapeFlags($this->config['dump_flags'], DatabaseType::POSTGRESQL);
        }

        $major = $this->serverMajorVersion();
        $binary = $this->binary('pg_dump', $major);

        // Flags must come before the database name (last positional argument)
        $command = sprintf(
            '%sPGPASSWORD=%s %s %s --host=%s --port=%s --username=%s%s %s',
            $this->sslEnvPrefix(),
            escapeshellarg($this->config['pass']),
            $binary,
            implode(' ', $options),
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            $extraFlags,
            escapeshellarg($this->config['database']),
        );

        $command .= ' -f '.escapeshellarg($outputPath);

        return new DatabaseOperationResult(command: $command, log: $this->legacyClientLog($binary, $major));
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        $major = $this->serverMajorVersion();

        if (($this->config['dump_format'] ?? 'plain') === 'custom') {
            $binary = $this->binary('pg_restore', $major);

            return new DatabaseOperationResult(command: sprintf(
                '%sPGPASSWORD=%s %s %s --host=%s --port=%s --username=%s --dbname=%s %s',
                $this->sslEnvPrefix(),
                escapeshellarg($this->config['pass']),
                $binary,
                implode(' ', $this->withPrivilegeOptions(self::RESTORE_CUSTOM_FORMAT_OPTIONS)),
                escapeshellarg($this->config['host']),
                escapeshellarg((string) $this->config['port']),
                escapeshellarg($this->config['user']),
                escapeshellarg($this->config['database']),
                escapeshellarg($inputPath),
            ), log: $this->legacyClientLog($binary, $major));
        }

        // -v ON_ERROR_STOP=1 makes psql abort and exit non-zero on the first
        // failed statement. Without it psql skips past errors and still exits 0,
        // so a restore that recreated nothing was reported as successful. The
        // dump is written with --clean --if-exists, so the DROP statements it
        // replays are not an error when the object is absent.
        return new DatabaseOperationResult(command: sprintf(
            '%sPGPASSWORD=%s %s --set=ON_ERROR_STOP=1 --host=%s --port=%s --username=%s %s -f %s',
            $this->sslEnvPrefix(),
            escapeshellarg($this->config['pass']),
            $this->binary('psql', $major),
            escapeshellarg($this->config['host']),
            escapeshellarg((string) $this->config['port']),
            escapeshellarg($this->config['user']),
            escapeshellarg($this->config['database']),
            escapeshellarg($inputPath)
        ));
    }

    /**
     * Path to the libpq client binary to run for this server.
     *
     * Servers below {@see LEGACY_CLIENT_MAJOR} get the matching older build
     * when the image carries one; everything else keeps the client on PATH.
     */
    private function binary(string $name, ?int $major): string
    {
        if ($major === null || $major > self::LEGACY_CLIENT_MAJOR) {
            return $name;
        }

        foreach ($this->clientBinDirs() as $directory) {
            $path = sprintf($directory, self::LEGACY_CLIENT_MAJOR).'/'.$name;

            if (is_executable($path)) {
                return $path;
            }
        }

        // No versioned build on this host, as on a native install without the
        // package. The client on PATH still dumps; its output just carries the
        // forward-incompatible SET this method exists to steer around, which
        // {@see legacyClientLog()} warns about rather than failing the backup.
        return $name;
    }

    /**
     * Warn when an older server had to be handled by the client on PATH.
     *
     * A backup that runs and warns beats one that refuses: the snapshot still
     * holds the data and still restores into a server as new as the client
     * that wrote it. What it may not do is go back into the server it came
     * from, and that is worth saying at the time rather than at recovery.
     */
    private function legacyClientLog(string $binary, ?int $major): ?DatabaseOperationLog
    {
        if ($major === null || $major > self::LEGACY_CLIENT_MAJOR || str_contains($binary, '/')) {
            return null;
        }

        return new DatabaseOperationLog(
            sprintf(
                'No PostgreSQL %d client is installed, so the client on PATH was used against this PostgreSQL %d server. '
                .'If it is newer, the snapshot will not restore into a server this old.',
                self::LEGACY_CLIENT_MAJOR,
                $major,
            ),
            'warning',
        );
    }

    /**
     * Where a versioned client build lands, on Alpine and on Debian/Ubuntu.
     *
     * @return list<string>
     */
    protected function clientBinDirs(): array
    {
        return [
            '/usr/libexec/postgresql%d',
            '/usr/lib/postgresql/%d/bin',
        ];
    }

    /**
     * Major version the server reports, or null when it cannot be read.
     */
    private function serverMajorVersion(): ?int
    {
        // Unset for configs that never reach a server, such as the UI preview.
        if (empty($this->config['probe_server_version'])) {
            return null;
        }

        try {
            $version = $this->createPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\PDOException) {
            return null;
        }

        // Reported as "16.15 (Debian 16.15-1.pgdg13+2)", so the leading integer is the major.
        $major = is_string($version) ? (int) $version : 0;

        return $major > 0 ? $major : null;
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

    /**
     * The statements that hand a restored database, and what the restore made
     * inside it, to $owner. Also what the restore modals preview, so what the
     * user is shown and what runs cannot drift apart.
     *
     * `database` always runs: pg_dump only ever describes the contents of a
     * database, never the database itself, so after a restore it belongs to the
     * role the restore connected as even when the dump preserved ownership.
     * That is the one thing preserving privileges cannot cover, and the only
     * thing left to do for such a snapshot — its objects come back under their
     * original owners, so the restore role owns nothing to hand over, and
     * REASSIGN OWNED BY is far too blunt to point at them anyway: it moves
     * every object the role owns in the database plus shared objects
     * cluster-wide. `objects` therefore only joins it for a portable dump, the
     * one case where the restore role recreated what it is handing over.
     *
     * @param  string  $connectionUser  username the restore connects as
     * @return array{database: string, objects?: string}
     */
    public static function ownershipStatements(string $schemaName, string $owner, string $connectionUser, bool $preservesPrivileges): array
    {
        $quote = static fn (string $identifier): string => '"'.str_replace('"', '""', $identifier).'"';

        $statements = [
            'database' => sprintf('ALTER DATABASE %s OWNER TO %s', $quote($schemaName), $quote($owner)),
        ];

        if (! $preservesPrivileges) {
            $statements['objects'] = sprintf('REASSIGN OWNED BY %s TO %s', $quote($connectionUser), $quote($owner));
        }

        return $statements;
    }

    /**
     * Hand the restored database to $username by running the statements
     * {@see ownershipStatements()} settles on.
     *
     * The two run on different connections: the database is altered over the
     * connection database, its objects reassigned from inside the restored one.
     */
    public function transferOwnership(string $schemaName, string $username, BackupLogger $logger): void
    {
        $statements = self::ownershipStatements(
            $schemaName,
            $username,
            (string) $this->config['user'],
            ! empty($this->config['dump_privileges']),
        );

        try {
            $adminPdo = $this->createPdo();
            $logger->logCommand($statements['database'], null, 0);
            $adminPdo->exec($statements['database']);

            if (! isset($statements['objects'])) {
                $logger->log('Snapshot carries its own ownership information, leaving the owners of the restored objects untouched');

                return;
            }

            $targetPdo = $this->createPdoForDatabase($schemaName);
            $logger->logCommand($statements['objects'], null, 0);
            $targetPdo->exec($statements['objects']);
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
