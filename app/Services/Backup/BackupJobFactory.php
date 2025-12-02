<?php

namespace App\Services\Backup;

use App\Models\BackupJob;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\Snapshot;

class BackupJobFactory
{
    /**
     * Create a BackupJob and Snapshot for a database server backup.
     *
     * @param  'manual'|'scheduled'  $method
     */
    public function createBackupJob(
        DatabaseServer $server,
        string $method,
        ?int $triggeredByUserId = null
    ): Snapshot {
        $job = BackupJob::create(['status' => 'pending']);

        $snapshot = Snapshot::create([
            'backup_job_id' => $job->id,
            'database_server_id' => $server->id,
            'backup_id' => $server->backup->id,
            'volume_id' => $server->backup->volume_id,
            'path' => '',
            'file_size' => 0,
            'checksum' => null,
            'started_at' => now(),
            'database_name' => $server->database_name ?? '',
            'database_type' => $server->database_type,
            'database_host' => $server->host,
            'database_port' => $server->port,
            'compression_type' => 'gzip',
            'method' => $method,
            'triggered_by_user_id' => $triggeredByUserId,
        ]);

        $snapshot->load(['job', 'volume', 'databaseServer']);

        return $snapshot;
    }

    /**
     * Create a BackupJob and Restore for a snapshot restore operation.
     */
    public function createRestoreJob(
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
