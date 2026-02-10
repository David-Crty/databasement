<?php

namespace App\Services;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\Volume;
use RuntimeException;

class DemoBackupService
{
    /**
     * Create a demo backup configuration for the application's own database.
     *
     * @param  string|null  $connectionName  Optional connection name to use (defaults to database.default config)
     *
     * @throws RuntimeException If database connection type is unsupported
     */
    public function createDemoBackup(?string $connectionName = null): DatabaseServer
    {
        $connection = $connectionName ?? config('database.default');
        $dbConfig = config("database.connections.{$connection}");

        $databaseType = match ($connection) {
            'mysql', 'mariadb' => 'mysql',
            'pgsql' => 'postgres',
            'sqlite' => 'sqlite',
            default => throw new RuntimeException("Unsupported database connection: {$connection}"),
        };

        // Create local volume for backups
        $volume = Volume::create([
            'name' => 'Local Backups',
            'type' => 'local',
            'config' => [
                'path' => '/data/backups',
            ],
        ]);

        // Create database server entry based on type
        if ($databaseType === 'sqlite') {
            $databaseServer = DatabaseServer::create([
                'name' => 'Databasement Database',
                'database_type' => 'sqlite',
                'sqlite_path' => $dbConfig['database'],
                'description' => 'Demo database',
            ]);
        } else {
            $databaseServer = DatabaseServer::create([
                'name' => 'Databasement Database',
                'host' => $dbConfig['host'] ?? '127.0.0.1',
                'port' => (int) ($dbConfig['port'] ?? ($databaseType === 'postgres' ? 5432 : 3306)),
                'database_type' => $databaseType,
                'username' => $dbConfig['username'] ?? '',
                'password' => $dbConfig['password'] ?? '',
                'database_names' => [$dbConfig['database'] ?? 'databasement'],
                'description' => 'Demo database',
            ]);
        }

        // Create backup configuration
        $dailySchedule = BackupSchedule::firstOrCreate(
            ['name' => 'Daily'],
            ['expression' => '0 2 * * *'],
        );

        Backup::create([
            'database_server_id' => $databaseServer->id,
            'volume_id' => $volume->id,
            'backup_schedule_id' => $dailySchedule->id,
            'retention_days' => 14,
        ]);

        return $databaseServer->load('backup.volume');
    }
}
