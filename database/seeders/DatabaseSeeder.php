<?php

namespace Database\Seeders;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
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
        // Users (all with password "password", no 2FA)
        User::factory()->withoutTwoFactor()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => User::ROLE_ADMIN,
        ]);

        User::factory()->withoutTwoFactor()->create([
            'name' => 'Member User',
            'email' => 'member@example.com',
            'role' => User::ROLE_MEMBER,
        ]);

        User::factory()->withoutTwoFactor()->create([
            'name' => 'Viewer User',
            'email' => 'viewer@example.com',
            'role' => User::ROLE_VIEWER,
        ]);

        // Shared volume and schedule
        $volume = Volume::create([
            'name' => 'Local Backups',
            'type' => 'local',
            'config' => ['path' => '/data/backups'],
        ]);

        $dailySchedule = BackupSchedule::firstOrCreate(
            ['name' => 'Daily'],
            ['expression' => '0 2 * * *'],
        );

        // MySQL server (from docker-compose)
        $mysql = DatabaseServer::create([
            'name' => 'Local MySQL',
            'host' => 'mysql',
            'port' => 3306,
            'database_type' => 'mysql',
            'username' => 'root',
            'password' => 'root',
            'backup_all_databases' => true,
        ]);

        // PostgreSQL server (from docker-compose)
        $postgres = DatabaseServer::create([
            'name' => 'Local PostgreSQL',
            'host' => 'postgres',
            'port' => 5432,
            'database_type' => 'postgres',
            'username' => 'root',
            'password' => 'root',
            'backup_all_databases' => true,
        ]);

        // SQLite server
        $sqlitePath = '/data/sample.sqlite';
        $this->createSqliteDatabase($sqlitePath);

        $sqlite = DatabaseServer::create([
            'name' => 'Local SQLite',
            'database_type' => 'sqlite',
            'database_names' => [$sqlitePath],
            'host' => '',
            'port' => 0,
            'username' => '',
            'password' => '',
        ]);

        // Redis server (from docker-compose)
        $redis = DatabaseServer::create([
            'name' => 'Local Redis',
            'host' => 'redis',
            'port' => 6379,
            'database_type' => 'redis',
            'username' => '',
            'password' => '',
            'backup_all_databases' => true,
        ]);

        // MongoDB server (from docker-compose)
        $mongodb = DatabaseServer::create([
            'name' => 'Local MongoDB',
            'host' => 'mongodb',
            'port' => 27017,
            'database_type' => 'mongodb',
            'username' => 'root',
            'password' => 'root',
            'backup_all_databases' => true,
            'extra_config' => ['auth_source' => 'admin'],
        ]);

        // Backup configurations
        foreach ([$mysql, $postgres, $sqlite, $redis, $mongodb] as $server) {
            Backup::create([
                'database_server_id' => $server->id,
                'volume_id' => $volume->id,
                'backup_schedule_id' => $dailySchedule->id,
                'retention_policy' => Backup::RETENTION_DAYS,
                'retention_days' => 30,
            ]);
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
