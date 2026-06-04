<?php

namespace Tests\Support;

use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
use App\Facades\AppConfig;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\Volume;
use App\Services\Backup\Databases\MongodbDatabase;
use App\Services\Backup\Databases\MssqlDatabase;
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
            'mssql' => [
                'host' => config('testing.databases.mssql.host'),
                'port' => (int) config('testing.databases.mssql.port'),
                'username' => config('testing.databases.mssql.username'),
                'password' => config('testing.databases.mssql.password'),
                'database' => config('testing.databases.mssql.database').$suffix,
                'database_type' => 'mssql',
            ],
            'firebird' => [
                'host' => config('testing.databases.firebird.host'),
                'port' => (int) config('testing.databases.firebird.port'),
                'username' => config('testing.databases.firebird.username'),
                'password' => config('testing.databases.firebird.password'),
                'database' => preg_replace('/\.fdb$/', $suffix.'.fdb', (string) config('testing.databases.firebird.database')),
                'database_type' => 'firebird',
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

        return Volume::factory()->local()->create([
            'name' => "Integration Test Volume ({$type})",
            'config' => ['root' => $storageDir],
        ]);
    }

    /**
     * Create an SFTP volume pointing at the SSH test container.
     */
    public static function createSftpVolume(): Volume
    {
        $ssh = self::getSshConfig();

        return Volume::factory()->sftp()->create([
            'name' => 'Integration Test SFTP Volume',
            'config' => [
                'host' => $ssh['host'],
                'port' => $ssh['port'],
                'username' => $ssh['username'],
                'password' => $ssh['password'],
                'root' => '/config/backups',
                'timeout' => 10,
            ],
        ]);
    }

    /**
     * Create a database server for integration tests. The database name /
     * SQLite file path is stashed on the server via a transient property so
     * {@see createBackup()} can inline it into the backup config below.
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
            'description' => "Integration test {$type} database server",
        ];

        if (isset($config['auth_source'])) {
            $serverData['extra_config'] = ['auth_source' => $config['auth_source']];
        }

        $serverData['organization_id'] = \App\Models\Organization::first()->id;
        $server = DatabaseServer::create($serverData);
        $server->pendingBackupState['database_names'] = [$config['database']];

        return $server;
    }

    /**
     * Create a backup configuration for the given server, picking a sensible
     * default selection mode based on the server's database type.
     */
    public static function createBackup(DatabaseServer $server, Volume $volume): Backup
    {
        $schedule = dailySchedule();

        $data = [
            'database_server_id' => $server->id,
            'volume_id' => $volume->id,
            'backup_schedule_id' => $schedule->id,
        ];

        $type = $server->database_type;
        $paths = $server->pendingBackupState['database_names'] ?? null;

        if ($type === DatabaseType::REDIS) {
            $data['database_selection_mode'] = DatabaseSelectionMode::All;
            $data['database_names'] = null;
        } else {
            $data['database_selection_mode'] = DatabaseSelectionMode::Selected;
            $data['database_names'] = $paths;
        }

        return Backup::create($data);
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
     * Resolve the primary database name/path for a test server. Checks the
     * pending backup state (for servers created via `createDatabaseServer`
     * that haven't materialised a Backup yet), then falls back to the first
     * persisted Backup row.
     */
    public static function resolveTestDatabaseName(DatabaseServer $server): string
    {
        $fromPending = $server->pendingBackupState['database_names'][0] ?? null;
        if ($fromPending !== null) {
            return $fromPending;
        }

        $backup = $server->backups()->first();

        return $backup?->database_names[0] ?? '';
    }

    /**
     * Load test data into a MongoDB database.
     */
    public static function loadMongodbTestData(DatabaseServer $server): void
    {
        $databaseName = self::resolveTestDatabaseName($server);
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
            'description' => 'Integration test Redis server',
            'organization_id' => \App\Models\Organization::first()->id,
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
     * Create a SQLite database server. The file path is stashed on
     * `pendingBackupState` so `createBackup()` can inline it.
     */
    public static function createSqliteDatabaseServer(string $sqlitePath): DatabaseServer
    {
        $server = DatabaseServer::create([
            'name' => 'Integration Test SQLite Server',
            'database_type' => 'sqlite',
            'description' => 'Integration test SQLite database',
            'organization_id' => \App\Models\Organization::first()->id,
        ]);

        $server->pendingBackupState['database_names'] = [$sqlitePath];

        return $server;
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
     * Run SQL against Firebird using the CLI client and return raw output.
     */
    public static function runFirebirdSql(DatabaseServer $server, array $statements, ?string $databaseName = null): string
    {
        $scriptFile = tempnam(sys_get_temp_dir(), 'firebird-sql-');
        if ($scriptFile === false) {
            throw new InvalidArgumentException('Unable to create temporary Firebird SQL script');
        }

        file_put_contents($scriptFile, implode(PHP_EOL, [...$statements, 'EXIT;']).PHP_EOL);

        $target = $databaseName !== null
            ? sprintf('%s/%d:%s', $server->host, $server->port, $databaseName)
            : null;

        $parts = [
            'isql',
            '-user',
            escapeshellarg($server->username),
            '-password',
            escapeshellarg($server->getDecryptedPassword()),
        ];

        if ($target !== null) {
            $parts[] = escapeshellarg($target);
        }

        $parts[] = '-i';
        $parts[] = escapeshellarg($scriptFile);
        $parts[] = '2>&1';

        exec(implode(' ', $parts), $output, $exitCode);
        @unlink($scriptFile);

        $joined = trim(implode(PHP_EOL, $output));

        if ($exitCode !== 0) {
            throw new InvalidArgumentException('Firebird SQL command failed: '.($joined !== '' ? $joined : 'unknown error'));
        }

        return $joined;
    }

    /**
     * Get SSH tunnel test configuration.
     *
     * @return array{host: string, port: int, username: string, password: string, mysql_host: string}
     */
    public static function getSshConfig(): array
    {
        return [
            'host' => config('testing.ssh.host'),
            'port' => (int) config('testing.ssh.port'),
            'username' => config('testing.ssh.username'),
            'password' => config('testing.ssh.password'),
            'mysql_host' => config('testing.ssh.mysql_host'),
        ];
    }

    /**
     * Create a persisted DatabaseServerSshConfig with real test SSH credentials.
     */
    public static function createSshConfig(): DatabaseServerSshConfig
    {
        $ssh = self::getSshConfig();

        return DatabaseServerSshConfig::create([
            'host' => $ssh['host'],
            'port' => $ssh['port'],
            'username' => $ssh['username'],
            'auth_type' => 'password',
            'password' => $ssh['password'],
            'organization_id' => \App\Models\Organization::first()->id,
        ]);
    }

    /**
     * Create a database server configured to connect through an SSH tunnel.
     *
     * The server's host is set to the database hostname as seen from the SSH container
     * (e.g. "mysql"), since the tunnel will forward connections from there.
     *
     * Currently only supports MySQL. Add config keys (e.g. testing.ssh.postgres_host)
     * and extend the match below when adding SSH tunnel tests for other types.
     */
    public static function createDatabaseServerWithSshTunnel(string $type): DatabaseServer
    {
        $dbConfig = self::getDatabaseConfig($type);
        $sshConfig = self::createSshConfig();
        $ssh = self::getSshConfig();

        $remoteHost = match ($type) {
            'mysql' => $ssh['mysql_host'],
            default => throw new InvalidArgumentException("SSH tunnel tests not configured for database type: {$type}"),
        };

        $server = DatabaseServer::create([
            'name' => "Integration Test {$type} SSH Tunnel Server",
            'host' => $remoteHost,
            'port' => $dbConfig['port'],
            'database_type' => $dbConfig['database_type'],
            'username' => $dbConfig['username'],
            'password' => $dbConfig['password'],
            'description' => "Integration test {$type} database server via SSH tunnel",
            'ssh_config_id' => $sshConfig->id,
            'organization_id' => \App\Models\Organization::first()->id,
        ]);
        $server->pendingBackupState['database_names'] = [$dbConfig['database']];

        return $server;
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

        if ($type === 'firebird') {
            try {
                self::runFirebirdSql($server, ['DROP DATABASE;'], $databaseName);
            } catch (InvalidArgumentException) {
                // Best-effort cleanup only.
            }

            return;
        }

        $pdo = DatabaseType::from($type)->createPdo($server);

        if ($type === 'mysql') {
            $pdo->exec("DROP DATABASE IF EXISTS `{$databaseName}`");
        } elseif ($type === 'postgres') {
            $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$databaseName}' AND pid <> pg_backend_pid()");
            $pdo->exec("DROP DATABASE IF EXISTS \"{$databaseName}\"");
        } elseif ($type === 'mssql') {
            $pdo->exec(MssqlDatabase::dropDatabaseIfExistsSql($databaseName));
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

        // MSSQL fixture loading needs T-SQL `GO` batch splitting (PDO can't run a
        // raw script with batch separators in one exec).
        if ($type === 'mssql') {
            self::loadMssqlTestData($server);

            return;
        }

        if ($type === 'firebird') {
            $databaseName = self::resolveTestDatabaseName($server);

            self::dropDatabase($type, $server, $databaseName);
            self::runFirebirdSql($server, [
                sprintf(
                    "CREATE DATABASE '%s/%d:%s' USER '%s' PASSWORD '%s';",
                    $server->host,
                    $server->port,
                    $databaseName,
                    str_replace("'", "''", $server->username),
                    str_replace("'", "''", $server->getDecryptedPassword()),
                ),
            ]);

            $fixtureFile = __DIR__.'/../Integration/fixtures/firebird-init.sql';
            $sql = array_values(array_filter(array_map('trim', explode(';', (string) file_get_contents($fixtureFile)))));
            self::runFirebirdSql(
                $server,
                array_map(static fn (string $statement) => $statement.';', $sql),
                $databaseName,
            );

            return;
        }

        $databaseName = self::resolveTestDatabaseName($server);

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

    /**
     * Drop+recreate the target MSSQL database, then run the fixture script.
     * The script uses `GO` batch separators which PDO cannot execute in one
     * call, so they're split client-side.
     */
    public static function loadMssqlTestData(DatabaseServer $server): void
    {
        $databaseName = self::resolveTestDatabaseName($server);
        $bracketedName = '['.str_replace(']', ']]', $databaseName).']';

        $adminPdo = DatabaseType::MSSQL->createPdo($server);
        $adminPdo->exec(MssqlDatabase::dropDatabaseIfExistsSql($databaseName));
        $adminPdo->exec("CREATE DATABASE {$bracketedName}");

        $pdo = DatabaseType::MSSQL->createPdo($server, $databaseName);

        $sql = file_get_contents(__DIR__.'/../Integration/fixtures/mssql-init.sql');
        foreach (self::splitTsqlBatches($sql) as $batch) {
            $pdo->exec($batch);
        }
    }

    /**
     * Split a T-SQL script on `GO` batch separators (case-insensitive, lines
     * containing only `GO` plus optional whitespace).
     *
     * @return array<int, string>
     */
    private static function splitTsqlBatches(string $sql): array
    {
        $batches = preg_split('/^\s*GO\s*$/im', $sql) ?: [];

        return array_values(array_filter(
            array_map('trim', $batches),
            fn (string $batch): bool => $batch !== '',
        ));
    }

    /**
     * Verify Firebird restore by counting rows in the test table.
     */
    public static function verifyFirebirdRestore(DatabaseServer $server, string $databaseName): int
    {
        $output = self::runFirebirdSql($server, [
            'SET HEADING OFF;',
            'SET LIST OFF;',
            'SET COUNT OFF;',
            'SELECT COUNT(*) FROM test_table;',
        ], $databaseName);

        preg_match('/\b(\d+)\b/', $output, $matches);

        return isset($matches[1]) ? (int) $matches[1] : 0;
    }
}
