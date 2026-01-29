<?php

namespace App\Services\Backup;

use App\Enums\CompressionType;
use App\Enums\DatabaseType;
use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;

class BackupJobFactory
{
    public function __construct(
        protected DatabaseListService $databaseListService
    ) {}

    /**
     * Create backup job(s) for a database server.
     *
     * For single database mode: Returns array with one Snapshot
     * For backup_all_databases mode: Returns array with Snapshot per database
     * For SQLite: Returns array with one Snapshot (database name derived from path)
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

        // SQLite: single snapshot, database name is the filename
        if ($server->database_type === DatabaseType::SQLITE) {
            $databaseName = basename($server->sqlite_path);
            $snapshots[] = $this->createSnapshot($server, $databaseName, $method, $triggeredByUserId);

            return $snapshots;
        }

        if ($server->backup_all_databases) {
            $databases = $this->databaseListService->listDatabases($server);

            if (empty($databases)) {
                throw new \RuntimeException('No databases found on the server to backup.');
            }

            foreach ($databases as $databaseName) {
                $snapshots[] = $this->createSnapshot($server, $databaseName, $method, $triggeredByUserId);
            }
        } else {
            if (empty($server->database_names)) {
                throw new \RuntimeException('No database names specified for the server to backup.');
            }
            foreach ($server->database_names as $databaseName) {
                $snapshots[] = $this->createSnapshot($server, $databaseName, $method, $triggeredByUserId);
            }
        }

        return $snapshots;
    }

    /**
     * Create a single snapshot for one database.
     *
     * @param  'manual'|'scheduled'  $method
     */
    protected function createSnapshot(
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
            'compression_type' => CompressionType::from(config('backup.compression')),
            'method' => $method,
            'metadata' => Snapshot::generateMetadata($server, $databaseName, $volume),
            'triggered_by_user_id' => $triggeredByUserId,
        ]);

        $snapshot->load(['job', 'volume', 'databaseServer']);

        return $snapshot;
    }

    /**
     * Create a BackupJob and Restore for a snapshot restore operation.
     */
    public function createRestore(
        Snapshot $snapshot,
        DatabaseServer $targetServer,
        string $schemaName,
        ?int $triggeredByUserId = null
    ): Restore {
        $job = BackupJob::create(['status' => 'pending']);

        $restore = Restore::create([
            'backup_job_id' => $job->id,
            'snapshot_id' => $snapshot->id,
            'target_server_id' => $targetServer->id,
            'schema_name' => $schemaName,
            'triggered_by_user_id' => $triggeredByUserId,
        ]);

        $restore->load(['job', 'snapshot.volume', 'snapshot.databaseServer', 'targetServer']);

        return $restore;
    }
}
