<?php

namespace Tests\Support;

use App\Enums\DatabaseType;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Volume;
use Illuminate\Support\Facades\ParallelTesting;
use InvalidArgumentException;
use PDO;

class IntegrationTestHelpers
{
    /**
     * Get the parallel testing token suffix for unique resource names.
     * Returns empty string if not running in parallel.
     */
    public static function getParallelSuffix(): string
    {
        $token = ParallelTesting::token();

        return $token ? "_{$token}" : '';
    }

    /**
     * Get database connection config for a given type.
     * When running in parallel, database names are suffixed with the process token to avoid conflicts.
     *
     * @return array{host: string, port: int, username: string, password: string, database: string, database_type: string}
     */
    public static function getDatabaseConfig(string $type): array
    {
        $suffix = self::getParallelSuffix();

        return match ($type) {
            'mysql' => [
                'host' => config('testing.databases.mysql.host'),
                'port' => (int) config('testing.databases.mysql.port'),
                'username' => config('testing.databases.mysql.username'),
                'password' => config('testing.databases.mysql.password'),
                'database' => config('testing.databases.mysql.database').$suffix,
                'database_type' => 'mysql',
            ],
            'postgres' => [
                'host' => config('testing.databases.postgres.host'),
                'port' => (int) config('testing.databases.postgres.port'),
                'username' => config('testing.databases.postgres.username'),
                'password' => config('testing.databases.postgres.password'),
                'database' => config('testing.databases.postgres.database').$suffix,
                'database_type' => 'postgres',
            ],
            'sqlite' => [
                'host' => config('backup.working_directory').'/test_connection'.$suffix.'.sqlite',
                'port' => 0,
                'username' => '',
                'password' => '',
                'database' => null,
                'database_type' => 'sqlite',
            ],
            default => throw new InvalidArgumentException("Unsupported database type: {$type}"),
        };
    }

    /**
     * Create a volume for integration tests.
     */
    public static function createVolume(string $type): Volume
    {
        $storageDir = config('backup.working_directory').'/storage';
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        return Volume::create([
            'name' => "Integration Test Volume ({$type})",
            'type' => 'local',
            'config' => ['root' => $storageDir],
        ]);
    }

    /**
     * Create a database server for integration tests.
     */
    public static function createDatabaseServer(string $type): DatabaseServer
    {
        $config = self::getDatabaseConfig($type);

        return DatabaseServer::create([
            'name' => "Integration Test {$type} Server",
            'host' => $config['host'],
            'port' => $config['port'],
            'database_type' => $config['database_type'],
            'username' => $config['username'],
            'password' => $config['password'],
            'database_names' => [$config['database']],
            'description' => "Integration test {$type} database server",
        ]);
    }

    /**
     * Create a backup configuration.
     */
    public static function createBackup(DatabaseServer $server, Volume $volume): Backup
    {
        return Backup::create([
            'database_server_id' => $server->id,
            'volume_id' => $volume->id,
            'recurrence' => 'manual',
        ]);
    }

    /**
     * Create a SQLite database server.
     */
    public static function createSqliteDatabaseServer(string $sqlitePath): DatabaseServer
    {
        return DatabaseServer::create([
            'name' => 'Integration Test SQLite Server',
            'database_type' => 'sqlite',
            'sqlite_path' => $sqlitePath,
            'description' => 'Integration test SQLite database',
        ]);
    }

    /**
     * Create a test SQLite database file with sample data.
     */
    public static function createTestSqliteDatabase(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }

        $pdo = new PDO("sqlite:{$path}");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE test_table (id INTEGER PRIMARY KEY, name TEXT, value INTEGER)');
        $pdo->exec("INSERT INTO test_table (name, value) VALUES ('item1', 100)");
        $pdo->exec("INSERT INTO test_table (name, value) VALUES ('item2', 200)");
        $pdo->exec("INSERT INTO test_table (name, value) VALUES ('item3', 300)");
    }

    /**
     * Connect to a database.
     */
    public static function connectToDatabase(string $type, DatabaseServer $server, string $databaseName): PDO
    {
        return DatabaseType::from($type)->createPdo($server, $databaseName);
    }

    /**
     * Drop a database.
     */
    public static function dropDatabase(string $type, DatabaseServer $server, string $databaseName): void
    {
        $pdo = DatabaseType::from($type)->createPdo($server);

        if ($type === 'mysql') {
            $pdo->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
        } elseif ($type === 'postgres') {
            $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$databaseName}' AND pid <> pg_backend_pid()");
            $pdo->exec("DROP DATABASE IF EXISTS \"{$databaseName}\"");
        }
    }

    /**
     * Load test data into a database.
     */
    public static function loadTestData(string $type, DatabaseServer $server): void
    {
        $databaseName = $server->database_names[0];

        $pdo = DatabaseType::from($type)->createPdo($server);

        if ($type === 'mysql') {
            $pdo->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
            $pdo->exec("CREATE DATABASE `{$databaseName}`");
        } elseif ($type === 'postgres') {
            $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$databaseName}' AND pid <> pg_backend_pid()");
            $pdo->exec("DROP DATABASE IF EXISTS \"{$databaseName}\"");
            $pdo->exec("CREATE DATABASE \"{$databaseName}\"");
        }

        $fixtureFile = match ($type) {
            'mysql' => __DIR__.'/../Integration/fixtures/mysql-init.sql',
            'postgres' => __DIR__.'/../Integration/fixtures/postgres-init.sql',
            default => throw new InvalidArgumentException("loadTestData does not support database type: {$type}. Use createTestSqliteDatabase for SQLite."),
        };

        $pdo = self::connectToDatabase($type, $server, $databaseName);
        $pdo->exec(file_get_contents($fixtureFile));
    }
}
