<?php

namespace App\Services\Backup;

use App\Enums\CompressionType;
use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
use App\Facades\AppConfig;
use App\Models\Backup;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BackupJobFactory
{
    public function __construct(
        protected DatabaseProvider $databaseProvider
    ) {}

    /**
     * Create backup job(s) for one backup configuration.
     *
     * For selected mode: Returns array with one Snapshot per selected database
     * For all mode: Returns array with Snapshot per database on server
     * For pattern mode: Returns array with Snapshot per matching database
     * For SQLite: Returns array with one Snapshot per configured path on the server
     *
     * @param  'manual'|'scheduled'  $method
     * @return Snapshot[]
     */
    public function createSnapshots(
        Backup $backup,
        string $method,
        ?int $triggeredByUserId = null
    ): array {
        $server = $backup->databaseServer;
        $snapshots = [];

        if ($server->database_type === DatabaseType::SQLITE) {
            foreach ($backup->database_names ?? [] as $databasePath) {
                $snapshots[] = $this->createSnapshot($backup, $databasePath, $method, $triggeredByUserId);
            }

            return $snapshots;
        }

        if ($server->database_type === DatabaseType::REDIS) {
            $snapshots[] = $this->createSnapshot($backup, 'all', $method, $triggeredByUserId);

            return $snapshots;
        }

        try {
            $databases = match ($backup->database_selection_mode) {
                DatabaseSelectionMode::All => $this->databaseProvider->listDatabasesForServer($server),
                DatabaseSelectionMode::Pattern => DatabaseServer::filterDatabasesByPattern(
                    $this->databaseProvider->listDatabasesForServer($server),
                    $backup->database_include_pattern ?? '',
                ),
                default => $backup->database_names ?? [],
            };
        } catch (\Throwable $e) {
            $this->recordPreflightFailure($backup, $method, $triggeredByUserId, $e);

            return [];
        }

        if (empty($databases)) {
            Log::warning("No databases found on server [{$server->name}] for backup [{$backup->id}].");
        }

        foreach ($databases as $databaseName) {
            $snapshots[] = $this->createSnapshot($backup, $databaseName, $method, $triggeredByUserId);
        }

        return $snapshots;
    }

    /**
     * Create a single snapshot for one database within a specific backup config.
     *
     * @param  'manual'|'scheduled'  $method
     */
    public function createSnapshot(
        Backup $backup,
        string $databaseName,
        string $method,
        ?int $triggeredByUserId = null
    ): Snapshot {
        $server = $backup->databaseServer;
        $job = BackupJob::create(['status' => 'pending']);
        $volume = $backup->volume;

        $snapshot = Snapshot::create([
            'backup_job_id' => $job->id,
            'database_server_id' => $server->id,
            'backup_id' => $backup->id,
            'volume_id' => $volume->id,
            'filename' => '',
            'file_size' => 0,
            'checksum' => null,
            'started_at' => now(),
            'database_name' => $databaseName,
            'database_type' => $server->database_type,
            'compression_type' => CompressionType::from(AppConfig::get('backup.compression')),
            'method' => $method,
            'metadata' => Snapshot::generateMetadata($server, $databaseName, $volume),
            'triggered_by_user_id' => $triggeredByUserId,
        ]);

        $snapshot->load(['job', 'volume', 'databaseServer']);

        return $snapshot;
    }

    /**
     * Record a pre-flight failure (e.g. database unreachable when listing
     * databases for All/Pattern modes) as a failed snapshot so monitoring
     * dashboards and notifications can pick it up.
     *
     * @param  'manual'|'scheduled'  $method
     */
    private function recordPreflightFailure(
        Backup $backup,
        string $method,
        ?int $triggeredByUserId,
        \Throwable $exception,
    ): void {
        $databaseName = match ($backup->database_selection_mode) {
            DatabaseSelectionMode::All => '(all databases)',
            DatabaseSelectionMode::Pattern => $backup->database_include_pattern ?: '(pattern)',
            default => '(preflight)',
        };

        $snapshot = $this->createSnapshot($backup, $databaseName, $method, $triggeredByUserId);
        $snapshot->job->log("Pre-flight failed: {$exception->getMessage()}", 'error', [
            'exception' => get_class($exception),
        ]);
        $snapshot->job->markFailed($exception);

        try {
            app(NotificationService::class)->notifyBackupFailed($snapshot, $exception);
        } catch (\Throwable $notificationException) {
            Log::warning('Pre-flight failure notification failed', [
                'snapshot_id' => $snapshot->id,
                'error' => $notificationException->getMessage(),
            ]);
        }
    }

    /**
     * Create a BackupJob and Restore for a snapshot restore operation.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ValidationException
     */
    public function createRestore(
        Snapshot $snapshot,
        DatabaseServer $targetServer,
        string $schemaName,
        ?int $triggeredByUserId = null,
        array $options = [],
        ?string $scheduledRestoreId = null,
    ): Restore {
        if ($snapshot->database_type !== $targetServer->database_type) {
            throw ValidationException::withMessages([
                'snapshot_id' => 'Snapshot database type does not match the target server.',
            ]);
        }

        if ($targetServer->isAppDatabase($schemaName)) {
            throw ValidationException::withMessages([
                'schema_name' => 'Cannot restore over the application database.',
            ]);
        }

        $job = BackupJob::create(['status' => 'pending']);

        $restore = Restore::create([
            'backup_job_id' => $job->id,
            'snapshot_id' => $snapshot->id,
            'target_server_id' => $targetServer->id,
            'schema_name' => $schemaName,
            'options' => $options ?: null,
            'triggered_by_user_id' => $triggeredByUserId,
            'scheduled_restore_id' => $scheduledRestoreId,
        ]);

        $restore->load(['job', 'snapshot.volume', 'snapshot.databaseServer', 'targetServer']);

        return $restore;
    }
}
