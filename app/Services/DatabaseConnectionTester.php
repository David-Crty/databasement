<?php

namespace App\Services;

use App\Enums\DatabaseType;
use App\Services\Backup\Databases\MysqlDatabase;
use App\Services\Backup\Databases\PostgresqlDatabase;
use PDO;
use PDOException;

class DatabaseConnectionTester
{
    /**
     * Test a database connection with the provided credentials using CLI tools.
     *
     * @param  array{database_type: string, host: string, port: int, username: string, password: string, database_name: ?string}  $config
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public static function test(array $config): array
    {
        $databaseType = DatabaseType::tryFrom($config['database_type']);

        if ($databaseType === null) {
            return [
                'success' => false,
                'message' => "Unsupported database type: {$config['database_type']}",
                'details' => [],
            ];
        }

        // SQLite: check if file exists and is readable
        if ($databaseType === DatabaseType::SQLITE) {
            return self::testSqliteConnection($config['host']);
        }

        return match (true) {
            $databaseType->isMysqlFamily() => self::testMysqlConnection($config),
            $databaseType === DatabaseType::POSTGRESQL => self::testPostgresqlConnection($config),
            default => [
                'success' => false,
                'message' => "Unsupported database type: {$config['database_type']}",
                'details' => [],
            ],
        };
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

        $startTime = microtime(true);
        exec($command.' 2>&1', $output, $exitCode);
        $pingMs = round((microtime(true) - $startTime) * 1000);

        if ($exitCode !== 0) {
            $errorOutput = implode("\n", $output);

            return [
                'success' => false,
                'message' => $errorOutput,
                'details' => [],
            ];
        }

        $details = [
            'ping_ms' => $pingMs,
        ];

        $outputText = implode("\n", $output);
        $details['output'] = $outputText;

        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => $details,
        ];
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
        $command = $postgresDatabase->getQueryCommand($dbConfig, 'SELECT version();');

        $startTime = microtime(true);
        exec($command.' 2>&1', $versionOutput, $exitCode);
        $pingMs = round((microtime(true) - $startTime) * 1000);

        if ($exitCode !== 0) {
            $errorOutput = implode("\n", $versionOutput);

            return [
                'success' => false,
                'message' => $errorOutput,
                'details' => [],
            ];
        }

        // Get SSL status
        $sslCommand = $postgresDatabase->getQueryCommand(
            $dbConfig,
            "SELECT CASE WHEN ssl THEN 'yes' ELSE 'no' END FROM pg_stat_ssl WHERE pid = pg_backend_pid();"
        );

        exec($sslCommand.' 2>&1', $sslOutput, $sslExitCode);
        $ssl = $sslExitCode === 0 ? trim(implode('', $sslOutput)) : 'unknown';

        $version = trim(implode('', $versionOutput));

        return [
            'success' => true,
            'message' => 'Connection successful',
            'details' => [
                'ping_ms' => $pingMs,
                'output' => json_encode(['dbms' => $version, 'ssl' => $ssl], JSON_PRETTY_PRINT),
            ],
        ];
    }

    /**
     * Test SQLite connection by checking if file exists and is readable.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    private static function testSqliteConnection(string $path): array
    {
        if (empty($path)) {
            return [
                'success' => false,
                'message' => 'Database path is required.',
                'details' => [],
            ];
        }

        if (! file_exists($path)) {
            return [
                'success' => false,
                'message' => 'Database file does not exist: '.$path,
                'details' => [],
            ];
        }

        if (! is_readable($path)) {
            return [
                'success' => false,
                'message' => 'Database file is not readable: '.$path,
                'details' => [],
            ];
        }

        if (! is_file($path)) {
            return [
                'success' => false,
                'message' => 'Path is not a file: '.$path,
                'details' => [],
            ];
        }

        // Try to open the SQLite database to verify it's valid
        try {
            $pdo = new PDO("sqlite:{$path}", null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // Get SQLite version
            $stmt = $pdo->query('SELECT sqlite_version()');
            $version = $stmt ? $stmt->fetchColumn() : 'unknown';

            // Get file size
            $fileSize = filesize($path);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => [
                    'output' => json_encode(['dbms' => "SQLite {$version}", 'file_size' => $fileSize, 'path' => $path], JSON_PRETTY_PRINT),
                ],
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Invalid SQLite database file: '.$e->getMessage(),
                'details' => [],
            ];
        }
    }
}
