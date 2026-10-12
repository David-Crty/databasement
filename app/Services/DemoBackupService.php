<?php

namespace App\Services;

use App\Actions\DatabaseServer\CreateDatabaseServerAction;
use App\Actions\Volume\CreateVolumeAction;
use App\Enums\DatabaseSelectionMode;
use App\Enums\VolumeType;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\Organization;
use RuntimeException;

class DemoBackupService
{
    /**
     * Create a demo backup configuration for the application's own database.
     *
     * @param  Organization  $organization  Organization that owns the created volume and server
     * @param  string|null  $connectionName  Optional connection name to use (defaults to database.default config)
     *
     * @throws RuntimeException If database connection type is unsupported
     */
    public function createDemoBackup(Organization $organization, ?string $connectionName = null): DatabaseServer
    {
        $connection = $connectionName ?? config('database.default');
        $dbConfig = config("database.connections.{$connection}");

        $databaseType = match ($connection) {
            'mysql', 'mariadb' => 'mysql',
            'pgsql' => 'postgres',
            'sqlite' => 'sqlite',
            default => throw new RuntimeException("Unsupported database connection: {$connection}"),
        };

        $volume = CreateVolumeAction::run($organization, VolumeType::LOCAL, 'Local Backups', ['path' => '/data/backups']);

        $server = $databaseType === 'sqlite'
            ? [
                'name' => 'Databasement Database',
                'database_type' => 'sqlite',
                'description' => 'Demo database',
            ]
            : [
                'name' => 'Databasement Database',
                'host' => $dbConfig['host'] ?? '127.0.0.1',
                'port' => (int) ($dbConfig['port'] ?? ($databaseType === 'postgres' ? 5432 : 3306)),
                'database_type' => $databaseType,
                'username' => $dbConfig['username'] ?? '',
                'password' => $dbConfig['password'] ?? '',
                'description' => 'Demo database',
            ];

        $dailySchedule = BackupSchedule::firstOrCreate(
            ['name' => 'Daily'],
            ['expression' => '0 2 * * *'],
        );

        $databaseServer = CreateDatabaseServerAction::run($organization, $server, backups: [[
            'volume_ids' => [$volume->id],
            'backup_schedule_id' => $dailySchedule->id,
            'retention_policy' => Backup::RETENTION_DAYS,
            'retention_days' => 14,
            'database_selection_mode' => DatabaseSelectionMode::Selected->value,
            'database_names' => [$databaseType === 'sqlite' ? $dbConfig['database'] : ($dbConfig['database'] ?? 'databasement')],
        ]]);

        return $databaseServer->load('backups.volumes');
    }
}
