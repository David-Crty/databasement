<?php

namespace Tests\Support;

use App\Enums\DatabaseType;
use App\Facades\AppConfig;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Volume;
use App\Services\Backup\Databases\MongodbDatabase;
use Illuminate\Support\Facades\ParallelTesting;
use InvalidArgumentException;
use MongoDB\Client as MongoClient;
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
     * @return array{host: string, port: int, username: string, password: string, database: string, database_type: string, auth_source?: string}
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
                'host' => AppConfig::get('backup.working_directory').'/test_connection'.$suffix.'.sqlite',
                'port' => 0,
                'username' => '',
                'password' => '',
                'database' => null,
                'database_type' => 'sqlite',
            ],
            'redis' => [
                'host' => config('testing.databases.redis.host'),
                'port' => (int) config('testing.databases.redis.port'),
                'username' => '',
                'password' => config('testing.databases.redis.password') ?? '',
                'database' => 'all',
                'database_type' => 'redis',
            ],
            'mongodb' => [
                'host' => config('testing.databases.mongodb.host'),
                'port' => (int) config('testing.databases.mongodb.port'),
                'username' => config('testing.databases.mongodb.username'),
                'password' => config('testing.databases.mongodb.password'),
                'database' => config('testing.databases.mongodb.database').$suffix,
                'database_type' => 'mongodb',
                'auth_source' => config('testing.databases.mongodb.auth_source'),
            ],
            default => throw new InvalidArgumentException("Unsupported database type: {$type}"),
        };
    }

    /**
     * Create a volume for integration tests.
     */
    public static function createVolume(string $type): Volume
    {
        $storageDir = AppConfig::get('backup.working_directory').'/storage';
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

        $serverData = [
            'name' => "Integration Test {$type} Server",
            'host' => $config['host'],
            'port' => $config['port'],
            'database_type' => $config['database_type'],
            'username' => $config['username'],
            'password' => $config['password'],
            'database_names' => [$config['database']],
            'description' => "Integration test {$type} database server",
        ];

        if (isset($config['auth_source'])) {
            $serverData['extra_config'] = ['auth_source' => $config['auth_source']];
        }

        return DatabaseServer::create($serverData);
    }

    /**
     * Create a backup configuration.
     */
    public static function createBackup(DatabaseServer $server, Volume $volume): Backup
    {
        $schedule = dailySchedule();

        return Backup::create([
            'database_server_id' => $server->id,
            'volume_id' => $volume->id,
            'backup_schedule_id' => $schedule->id,
        ]);
    }

    /**
     * Build a MongoDB client for integration tests.
     */
    private static function createMongoClient(DatabaseServer $server): MongoClient
    {
        $uri = MongodbDatabase::buildConnectionUri(
            $server->host,
            $server->port,
            $server->username,
            $server->getDecryptedPassword(),
            $server->getExtraConfig('auth_source', 'admin'),
        );

        return new MongoClient($uri);
    }

    /**
     * Load test data into a MongoDB database.
     */
    public static function loadMongodbTestData(DatabaseServer $server): void
    {
        $databaseName = $server->database_names[0];
        $client = self::createMongoClient($server);
        $db = $client->selectDatabase($databaseName);

        // Drop existing database
        $db->drop();

        // Insert test fixture data
        $db->selectCollection('products')->insertMany([
            ['name' => 'Widget A', 'price' => 9.99, 'stock' => 150],
            ['name' => 'Widget B', 'price' => 24.99, 'stock' => 75],
            ['name' => 'Gadget Pro', 'price' => 49.99, 'stock' => 30],
            ['name' => 'Mega Bundle', 'price' => 99.99, 'stock' => 10],
        ]);

        $db->selectCollection('orders')->insertMany([
            ['product' => 'Widget A', 'quantity' => 2, 'total' => 19.98],
            ['product' => 'Widget B', 'quantity' => 1, 'total' => 24.99],
            ['product' => 'Gadget Pro', 'quantity' => 3, 'total' => 149.97],
            ['product' => 'Widget A', 'quantity' => 5, 'total' => 49.95],
        ]);
    }

    /**
     * Drop a MongoDB database.
     */
    public static function dropMongodbDatabase(DatabaseServer $server, string $databaseName): void
    {
        $client = self::createMongoClient($server);
        $client->selectDatabase($databaseName)->drop();
    }

    /**
     * Verify MongoDB restore by checking collection count.
     */
    public static function verifyMongodbRestore(DatabaseServer $server, string $databaseName): int
    {
        $client = self::createMongoClient($server);
        $db = $client->selectDatabase($databaseName);

        $collections = iterator_to_array($db->listCollectionNames());

        return count($collections);
    }

    /**
     * Create a Redis database server for integration tests.
     */
    public static function createRedisDatabaseServer(): DatabaseServer
    {
        $config = self::getDatabaseConfig('redis');

        return DatabaseServer::create([
            'name' => 'Integration Test Redis Server',
            'host' => $config['host'],
            'port' => $config['port'],
            'database_type' => 'redis',
            'username' => $config['username'],
            'password' => $config['password'],
            'backup_all_databases' => true,
            'description' => 'Integration test Redis server',
        ]);
    }

    /**
     * Load test data into a Redis server.
     */
    public static function loadRedisTestData(DatabaseServer $server): void
    {
        $fixtureFile = __DIR__.'/../Integration/fixtures/redis-init.txt';
        $host = escapeshellarg($server->host);
        $port = escapeshellarg((string) $server->port);
        $password = $server->getDecryptedPassword();
        $authFlags = ! empty($password) ? '-a '.escapeshellarg($password).' --no-auth-warning ' : '';

        // Flush existing data and load fixture
        exec("redis-cli -h {$host} -p {$port} {$authFlags}FLUSHALL 2>/dev/null");
        exec("redis-cli -h {$host} -p {$port} {$authFlags}< {$fixtureFile} 2>/dev/null");
    }

    /**
     * Create a SQLite database server.
     */
    public static function createSqliteDatabaseServer(string $sqlitePath): DatabaseServer
    {
        return DatabaseServer::create([
            'name' => 'Integration Test SQLite Server',
            'database_type' => 'sqlite',
            'database_names' => [$sqlitePath],
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
        if ($type === 'mongodb') {
            self::dropMongodbDatabase($server, $databaseName);

            return;
        }

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
        // Redis uses its own data loading mechanism
        if ($type === 'redis') {
            self::loadRedisTestData($server);

            return;
        }

        // MongoDB uses its own data loading mechanism
        if ($type === 'mongodb') {
            self::loadMongodbTestData($server);

            return;
        }

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
