<?php

namespace App\Services\Backup;

use App\Enums\SnapshotFileStatus;
use App\Models\AgentJob;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Services\Agent\AgentJobPayloadBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for deleting a snapshot on behalf of retention or a user.
 *
 * Servers backed by a remote agent often write to volumes only the agent can
 * reach, so the app cannot delete their files: the removal is delegated to the
 * agent and the snapshot record is kept until the agent confirms it. Servers
 * without an agent keep deleting synchronously, exactly as before.
 */
class DeleteSnapshotAction
{
    public function __construct(private AgentJobPayloadBuilder $payloadBuilder) {}

    /**
     * Delete a snapshot, or hand its files to the agent and keep the record.
     *
     * @param  bool  $keepFiles  Drop the record but leave the volume files alone
     * @return bool True when the record was removed, false when the removal is
     *              pending an agent confirmation
     */
    public function execute(Snapshot $snapshot, bool $keepFiles = false): bool
    {
        if ($keepFiles) {
            $snapshot->skipFileCleanup = true;
            $snapshot->delete();

            return true;
        }

        $files = $snapshot->files()->with('volume')->get()
            ->filter(fn (SnapshotFile $file) => $file->needsVolumeCleanup())
            ->values();

        if ($snapshot->databaseServer->agent_id === null || $files->isEmpty()) {
            $snapshot->delete();

            return true;
        }

        // A previous run may already have delegated this snapshot; retention
        // re-examines it every run while the record is still there. The check
        // sits inside the transaction, behind a lock on the snapshot row, so a
        // retention run and a user deletion racing each other cannot both pass
        // it and send two agents after the same files.
        DB::transaction(function () use ($snapshot, $files): void {
            Snapshot::query()->whereKey($snapshot->id)->lockForUpdate()->first();

            if ($this->hasPendingCleanupJob($snapshot)) {
                return;
            }

            foreach ($files as $file) {
                $file->update([
                    'status' => SnapshotFileStatus::Deleting,
                    'error' => null,
                ]);
            }

            AgentJob::create([
                'type' => AgentJob::TYPE_CLEANUP,
                'database_server_id' => $snapshot->database_server_id,
                'snapshot_id' => $snapshot->id,
                'status' => AgentJob::STATUS_PENDING,
                'payload' => $this->payloadBuilder->buildCleanup($snapshot, $files),
            ]);
        });

        return false;
    }

    private function hasPendingCleanupJob(Snapshot $snapshot): bool
    {
        return AgentJob::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('type', AgentJob::TYPE_CLEANUP)
            ->whereIn('status', [
                AgentJob::STATUS_PENDING,
                AgentJob::STATUS_CLAIMED,
                AgentJob::STATUS_RUNNING,
            ])
            ->exists();
    }
}
