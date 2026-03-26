<?php

namespace App\Services\Backup;

use App\Enums\CompressionType;
use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
use App\Facades\AppConfig;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;
use App\Services\Backup\Databases\DatabaseProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BackupJobFactory
{
    public function __construct(
        protected DatabaseProvider $databaseProvider
    ) {}

    /**
     * Create backup job(s) for a database server.
     *
     * For selected mode: Returns array with one Snapshot per selected database
     * For all mode: Returns array with Snapshot per database on server
     * For pattern mode: Returns array with Snapshot per matching database
     * For SQLite: Returns array with one Snapshot per configured path
     *
     * @param  'manual'|'scheduled'  $method
     * @return Snapshot[]
     */
    public function createSnapshots(
        DatabaseServer $server,
        string $method,
        ?int $triggeredByUserId = null
    ): array {
        $snapshots = [];

        // SQLite: one snapshot per database file path
        if ($server->database_type === DatabaseType::SQLITE) {
            foreach ($server->database_names ?? [] as $databasePath) {
                $snapshots[] = $this->createSnapshot($server, $databasePath, $method, $triggeredByUserId);
            }

            return $snapshots;
        }

        // Redis: single snapshot, dumps entire instance
        if ($server->database_type === DatabaseType::REDIS) {
            $snapshots[] = $this->createSnapshot($server, 'all', $method, $triggeredByUserId);

            return $snapshots;
        }

        // Agent-backed servers with all/pattern mode need a discovery phase —
        // the web app can't reach the database, only the agent can list databases.
        if ($server->agent_id && in_array($server->database_selection_mode, [DatabaseSelectionMode::All, DatabaseSelectionMode::Pattern], true)) {
            return [];
        }

        $databases = match ($server->database_selection_mode) {
            DatabaseSelectionMode::All => $this->databaseProvider->listDatabasesForServer($server),
            DatabaseSelectionMode::Pattern => DatabaseServer::filterDatabasesByPattern(
                $this->databaseProvider->listDatabasesForServer($server),
                $server->database_include_pattern ?? ''
            ),
            default => $server->database_names ?? [],
        };

        if (empty($databases)) {
            Log::warning("No databases found on server [{$server->name}] to backup.");
        }

        foreach ($databases as $databaseName) {
            $snapshots[] = $this->createSnapshot($server, $databaseName, $method, $triggeredByUserId);
        }

        return $snapshots;
    }

    /**
     * Create a single snapshot for one database.
     *
     * @param  'manual'|'scheduled'  $method
     */
    public function createSnapshot(
        DatabaseServer $server,
        string $databaseName,
        string $method,
        ?int $triggeredByUserId = null
    ): Snapshot {
        $job = BackupJob::create(['status' => 'pending']);
        $volume = $server->backup->volume;

        $snapshot = Snapshot::create([
            'backup_job_id' => $job->id,
            'database_server_id' => $server->id,
            'backup_id' => $server->backup->id,
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
        ]);

        $restore->load(['job', 'snapshot.volume', 'snapshot.databaseServer', 'targetServer']);

        return $restore;
    }
}
