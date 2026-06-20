<?php

namespace Database\Seeders;

use App\Enums\VolumeType;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\Organization;
use App\Models\User;
use App\Models\Volume;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Default organization
        $defaultOrg = Organization::firstOrCreate(
            ['is_default' => true],
            ['name' => 'Default'],
        );

        // Users (all with password "password", no 2FA)
        User::factory()->withoutTwoFactor()->superAdmin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        User::factory()->withoutTwoFactor()->create([
            'name' => 'Member User',
            'email' => 'member@example.com',
        ]);

        User::factory()->withoutTwoFactor()->viewer()->create([
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
        ]);

        // Shared volume and schedule
        $volume = Volume::create([
            'name' => 'Local',
            'type' => 'local',
            'config' => ['path' => '/data/backups'],
            'organization_id' => $defaultOrg->id,
        ]);

        // S3 volume backed by the local rustfs service (docker-compose).
        // Internal endpoint reaches rustfs over the compose network; the public
        // endpoint (host-published port) is used for presigned download URLs.
        $s3Config = [
            'bucket' => 'databasement-backups',
            'prefix' => '',
            'region' => 'us-east-1',
            'access_key_id' => 'rustfsadmin',
            'secret_access_key' => 'rustfsadmin',
            'custom_endpoint' => 'http://rustfs:9000',
            'public_endpoint' => 'http://localhost:9000',
            'use_path_style_endpoint' => true,
            'custom_role_arn' => '',
            'role_session_name' => '',
            'sts_endpoint' => '',
        ];

        $this->createRustfsBucket($s3Config);

        $s3Volume = Volume::create([
            'name' => 'RustFS (S3)',
            'type' => 's3',
            'config' => VolumeType::S3->encryptSensitiveFields($s3Config),
            'organization_id' => $defaultOrg->id,
        ]);

        $dailySchedule = BackupSchedule::firstOrCreate(
            ['name' => 'Daily'],
            ['expression' => '0 2 * * *'],
        );

        // SSH tunnel config pointing at the openssh-server container.
        // From inside that container, MySQL is reachable at mysql:3306.
        $mysqlSshConfig = DatabaseServerSshConfig::create([
            'host' => 'ssh',
            'port' => 2222,
            'username' => 'testuser',
            'auth_type' => 'password',
            'password' => 'testpass',
            'organization_id' => $defaultOrg->id,
        ]);

        // MySQL server (from docker-compose) — connects through the SSH tunnel above
        $mysql = DatabaseServer::create([
            'name' => 'Local MySQL',
            'host' => 'mysql',
            'port' => 3306,
            'database_type' => 'mysql',
            'username' => 'root',
            'password' => 'root',
            'ssh_config_id' => $mysqlSshConfig->id,
            'organization_id' => $defaultOrg->id,
        ]);

        // PostgreSQL server (from docker-compose)
        $postgres = DatabaseServer::create([
            'name' => 'Local PostgreSQL',
            'host' => 'postgres',
            'port' => 5432,
            'database_type' => 'postgres',
            'username' => 'root',
            'password' => 'root',
            'organization_id' => $defaultOrg->id,
        ]);

        // SQLite server
        $sqlitePath = '/data/sample.sqlite';
        $this->createSqliteDatabase($sqlitePath);

        $sqlite = DatabaseServer::create([
            'name' => 'Local SQLite',
            'database_type' => 'sqlite',
            'host' => '',
            'port' => 0,
            'username' => '',
            'password' => '',
            'organization_id' => $defaultOrg->id,
        ]);

        // Redis server (from docker-compose)
        $redis = DatabaseServer::create([
            'name' => 'Local Redis',
            'host' => 'redis',
            'port' => 6379,
            'database_type' => 'redis',
            'username' => '',
            'password' => '',
            'organization_id' => $defaultOrg->id,
        ]);

        // MongoDB server (from docker-compose)
        $mongodb = DatabaseServer::create([
            'name' => 'Local MongoDB',
            'host' => 'mongodb',
            'port' => 27017,
            'database_type' => 'mongodb',
            'username' => 'root',
            'password' => 'root',
            'extra_config' => ['auth_source' => 'admin'],
            'organization_id' => $defaultOrg->id,
        ]);

        // Microsoft SQL Server (from docker-compose)
        $mssql = DatabaseServer::create([
            'name' => 'Local SQL Server',
            'host' => 'mssql',
            'port' => 1433,
            'database_type' => 'mssql',
            'username' => 'sa',
            'password' => 'Databasement!Strong1',
            'organization_id' => $defaultOrg->id,
        ]);

        // Firebird server (from docker-compose)
        $firebirdPath = '/var/lib/firebird/data/sample.fdb';
        $firebird = DatabaseServer::create([
            'name' => 'Local Firebird',
            'host' => 'firebird',
            'port' => 3050,
            'database_type' => 'firebird',
            'username' => 'sysdba',
            'password' => 'masterkey',
            'organization_id' => $defaultOrg->id,
        ]);

        // Backup configurations (database_selection_mode lives on Backup now)
        $backupDefaults = [
            'volume_id' => $volume->id,
            'backup_schedule_id' => $dailySchedule->id,
            'retention_policy' => Backup::RETENTION_DAYS,
            'retention_days' => 30,
            'database_selection_mode' => 'all',
        ];

        foreach ([$mysql, $redis, $mongodb, $mssql] as $server) {
            Backup::create(array_merge($backupDefaults, [
                'database_server_id' => $server->id,
            ]));
        }

        // PostgreSQL backs up to the rustfs S3 volume (local S3 testing)
        Backup::create(array_merge($backupDefaults, [
            'database_server_id' => $postgres->id,
            'volume_id' => $s3Volume->id,
        ]));

        // SQLite backup uses 'selected' mode with explicit file paths
        Backup::create(array_merge($backupDefaults, [
            'database_server_id' => $sqlite->id,
            'database_selection_mode' => 'selected',
            'database_names' => [$sqlitePath],
        ]));

        // Firebird backup uses 'selected' mode with explicit file paths
        Backup::create(array_merge($backupDefaults, [
            'database_server_id' => $firebird->id,
            'database_selection_mode' => 'selected',
            'database_names' => [$firebirdPath],
        ]));
    }

    /**
     * Ensure the rustfs bucket exists so the seeded S3 volume works out of the box.
     * Best-effort: never fail seeding if rustfs is unreachable or the bucket exists.
     *
     * @param  array<string, mixed>  $config
     */
    private function createRustfsBucket(array $config): void
    {
        try {
            $client = new \Aws\S3\S3Client([
                'version' => 'latest',
                'region' => $config['region'],
                'endpoint' => $config['custom_endpoint'],
                'use_path_style_endpoint' => true,
                'credentials' => [
                    'key' => $config['access_key_id'],
                    'secret' => $config['secret_access_key'],
                ],
            ]);

            if (! $client->doesBucketExist($config['bucket'])) {
                $client->createBucket(['Bucket' => $config['bucket']]);
            }
        } catch (\Throwable $e) {
            $this->command?->warn("Could not provision rustfs bucket '{$config['bucket']}': {$e->getMessage()}");
        }
    }

    /**
     * Create a sample SQLite database with some tables and data.
     */
    private function createSqliteDatabase(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (file_exists($path)) {
            unlink($path);
        }

        $pdo = new \PDO("sqlite:{$path}");
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            price REAL NOT NULL,
            stock INTEGER NOT NULL DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        $pdo->exec('CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL REFERENCES products(id),
            quantity INTEGER NOT NULL,
            total REAL NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )');

        $stmt = $pdo->prepare('INSERT INTO products (name, price, stock) VALUES (?, ?, ?)');
        $products = [
            ['Widget A', 9.99, 150],
            ['Widget B', 24.99, 75],
            ['Gadget Pro', 49.99, 30],
            ['Mega Bundle', 99.99, 10],
        ];
        foreach ($products as $product) {
            $stmt->execute($product);
        }

        $stmt = $pdo->prepare('INSERT INTO orders (product_id, quantity, total) VALUES (?, ?, ?)');
        $orders = [
            [1, 2, 19.98],
            [2, 1, 24.99],
            [3, 3, 149.97],
            [1, 5, 49.95],
        ];
        foreach ($orders as $order) {
            $stmt->execute($order);
        }
    }
}
