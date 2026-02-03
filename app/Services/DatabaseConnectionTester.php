<?php

namespace App\Services;

use App\Enums\DatabaseType;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use App\Support\Formatters;
use PDO;
use PDOException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class DatabaseConnectionTester
{
    private const TIMEOUT_SECONDS = 10;

    private static ?SshTunnelService $sshTunnelService = null;

    /**
     * Test a database connection with the provided credentials using CLI tools.
     *
     * @param  array{database_type: string, host: string, port: int, username: string, password: string, database_name: ?string}  $config
     * @param  DatabaseServerSshConfig|null  $sshConfig  Optional SSH config for tunnel
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public static function test(array $config, ?DatabaseServerSshConfig $sshConfig = null): array
    {
        $databaseType = DatabaseType::tryFrom($config['database_type']);

        if ($databaseType === null) {
            return self::error("Unsupported database type: {$config['database_type']}");
        }

        // SSH tunneling is not supported for SQLite (uses local file paths)
        if ($databaseType === DatabaseType::SQLITE && $sshConfig !== null) {
            return self::error('SSH tunneling is not supported for SQLite databases.');
        }

        // Handle SSH tunnel if configured (only for client-server databases)
        $tunnelEndpoint = null;

        if ($sshConfig !== null) {
            // Test SSH connection first
            $sshResult = SshTunnelService::testConnection($sshConfig);
            if (! $sshResult['success']) {
                return self::error('SSH connection failed: '.$sshResult['message']);
            }

            // Establish SSH tunnel for the database test
            try {
                self::$sshTunnelService = new SshTunnelService;
                $tunnelEndpoint = self::$sshTunnelService->establish(
                    DatabaseServer::forConnectionTest([
                        'host' => $config['host'],
                        'port' => $config['port'],
                    ], $sshConfig)
                );
            } catch (\Throwable $e) {
                self::closeTunnel();

                return self::error('Failed to establish SSH tunnel: '.$e->getMessage());
            }
        }

        try {
            // Use tunnel endpoint if active
            if ($tunnelEndpoint !== null) {
                $config['host'] = $tunnelEndpoint['host'];
                $config['port'] = $tunnelEndpoint['port'];
            }

            $result = match ($databaseType) {
                DatabaseType::MYSQL => self::testMysqlConnection($config),
                DatabaseType::POSTGRESQL => self::testPostgresqlConnection($config),
                DatabaseType::SQLITE => self::testSqliteConnection($config['host']),
            };

            // Add SSH tunnel info to success details
            if ($result['success'] && $sshConfig !== null) {
                $result['details']['ssh_tunnel'] = true;
                $result['details']['ssh_host'] = $sshConfig->host;
            }

            return $result;
        } finally {
            self::closeTunnel();
        }
    }

    /**
     * Close the SSH tunnel if active.
     */
    private static function closeTunnel(): void
    {
        if (self::$sshTunnelService !== null) {
            self::$sshTunnelService->close();
            self::$sshTunnelService = null;
        }
    }

    /**
     * Execute a command with timeout and return the result.
     *
     * @return array{success: true, output: string, durationMs: int}|array{success: false, message: string}
     */
    private static function executeWithTimeout(string $command): array
    {
        $process = Process::fromShellCommandLine($command);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        $startTime = microtime(true);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            return [
                'success' => false,
                'message' => 'Connection timed out after '.Formatters::humanDuration($durationMs).'. Please check the host and port are correct and accessible.',
            ];
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        if (! $process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput() ?: $process->getOutput());

            return [
                'success' => false,
                'message' => $errorOutput ?: 'Connection failed with exit code '.$process->getExitCode(),
            ];
        }

        return [
            'success' => true,
            'output' => trim($process->getOutput()),
            'durationMs' => $durationMs,
        ];
    }

    /**
     * Build an error response.
     *
     * @return array{success: false, message: string, details: array<string, mixed>}
     */
    private static function error(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'details' => [],
        ];
    }

    /**
     * Build a success response.
     *
     * @param  array<string, mixed>  $details
     * @return array{success: true, message: string, details: array<string, mixed>}
     */
    private static function success(array $details = []): array
    {
        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => $details,
        ];
    }

    /**
     * Test MySQL/MariaDB connection using CLI.
     *
     * @param  array{database_type: string, host: string, port: int, username: string, password: string, database_name: ?string}  $config
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    private static function testMysqlConnection(array $config): array
    {
        $mysqlDatabase = new MysqlDatabase;
        $command = $mysqlDatabase->getStatusCommand([
            'host' => $config['host'],
            'port' => $config['port'],
            'user' => $config['username'],
            'pass' => $config['password'],
        ]);

        $result = self::executeWithTimeout($command);

        if (! $result['success']) {
            return self::error($result['message']);
        }

        return self::success([
            'ping_ms' => $result['durationMs'],
            'output' => $result['output'],
        ]);
    }

    /**
     * Test PostgreSQL connection using CLI.
     *
     * @param  array{database_type: string, host: string, port: int, username: string, password: string, database_name: ?string}  $config
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    private static function testPostgresqlConnection(array $config): array
    {
        $postgresDatabase = new PostgresqlDatabase;
        $dbConfig = [
            'host' => $config['host'],
            'port' => $config['port'],
            'user' => $config['username'],
            'pass' => $config['password'],
            'database' => $config['database_name'] ?? 'postgres',
        ];

        // Get version
        $versionCommand = $postgresDatabase->getQueryCommand($dbConfig, 'SELECT version();');
        $result = self::executeWithTimeout($versionCommand);

        if (! $result['success']) {
            return self::error($result['message']);
        }

        $version = $result['output'];
        $durationMs = $result['durationMs'];

        // Get SSL status (non-critical, ignore failures)
        $sslCommand = $postgresDatabase->getQueryCommand(
            $dbConfig,
            "SELECT CASE WHEN ssl THEN 'yes' ELSE 'no' END FROM pg_stat_ssl WHERE pid = pg_backend_pid();"
        );
        $sslResult = self::executeWithTimeout($sslCommand);
        $ssl = $sslResult['success'] ? $sslResult['output'] : 'unknown';

        return self::success([
            'ping_ms' => $durationMs,
            'output' => json_encode(['dbms' => $version, 'ssl' => $ssl], JSON_PRETTY_PRINT),
        ]);
    }

    /**
     * Test SQLite connection by checking if file exists and is readable.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    private static function testSqliteConnection(string $path): array
    {
        if (empty($path)) {
            return self::error('Database path is required.');
        }

        if (! file_exists($path)) {
            return self::error('Database file does not exist: '.$path);
        }

        if (! is_readable($path)) {
            return self::error('Database file is not readable: '.$path);
        }

        if (! is_file($path)) {
            return self::error('Path is not a file: '.$path);
        }

        try {
            $pdo = new PDO("sqlite:{$path}", null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // Query sqlite_master to verify the file is a valid SQLite database
            $pdo->query('SELECT 1 FROM sqlite_master LIMIT 1');

            // Get SQLite version
            $stmt = $pdo->query('SELECT sqlite_version()');
            $version = $stmt ? $stmt->fetchColumn() : 'unknown';

            // Get file size
            $fileSize = filesize($path);

            return self::success([
                'output' => json_encode(['dbms' => "SQLite {$version}", 'file_size' => $fileSize, 'path' => $path], JSON_PRETTY_PRINT),
            ]);
        } catch (PDOException $e) {
            return self::error('Invalid SQLite database file: '.$e->getMessage());
        }
    }
}
