<?php

namespace App\Services\Backup;

use App\Enums\AgentJobType;
use App\Enums\VolumeType;
use App\Exceptions\Backup\SnapshotFilesNotDeletedException;
use App\Models\AgentJob;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Services\Agent\AgentJobPayloadBuilder;
use Throwable;

class DeleteSnapshotAction
{
    public function __construct(
        private AgentJobPayloadBuilder $payloadBuilder,
    ) {}

    /**
     * Delete a snapshot with its file on every volume, or leave the files in
     * place when asked to.
     *
     * The files of a server backed by an agent are deleted by that agent,
     * which may be the only one able to reach them, so the snapshot stays
     * marked as deleting until it reports back. Copies on the app's own local
     * disk are still deleted here.
     *
     * @return bool True when the snapshot was deleted, false when its agent finishes the deletion
     *
     * @throws SnapshotFilesNotDeletedException When a file could not be removed, which keeps the snapshot
     */
    public function execute(Snapshot $snapshot, bool $keepFiles = false): bool
    {
        if ($keepFiles) {
            $this->deleteRecord($snapshot);

            return true;
        }

        $copies = $snapshot->files()->completed()->with('volume')->get();

        $hasAgent = $snapshot->databaseServer->agent_id !== null;
        $agentCopies = $copies->filter(fn (SnapshotFile $file): bool => $hasAgent && $file->volume->type !== VolumeType::LOCAL->value);
        $appCopies = $copies->diff($agentCopies);

        $this->recordRemovals($snapshot, $appCopies
            ->mapWithKeys(fn (SnapshotFile $file): array => [$file->id => $this->removeFile($file)])
            ->all());

        if ($agentCopies->isNotEmpty()) {
            $snapshot->update(['deleting' => true]);

            AgentJob::enqueue(AgentJobType::Cleanup, $snapshot->database_server_id, $this->payloadBuilder->buildCleanup($snapshot, $agentCopies), [
                'snapshot_id' => $snapshot->id,
            ]);

            return false;
        }

        $this->deleteUnlessFilesRemain($snapshot);

        return true;
    }

    /**
     * Forget the copies whose file is gone, so a retry only revisits the
     * others, and record on each of those why its file is still there.
     *
     * @param  array<string, string|null>  $outcomes  Per copy id: null once its file is gone, the error otherwise
     */
    public function recordRemovals(Snapshot $snapshot, array $outcomes): void
    {
        foreach ($snapshot->files()->completed()->whereKey(array_keys($outcomes))->get() as $file) {
            if ($outcomes[$file->id] === null) {
                $file->delete();
            } else {
                $file->update(['error' => $outcomes[$file->id]]);
            }
        }
    }

    /**
     * Delete the snapshot once no uploaded copy is left. Otherwise keep it,
     * so no file stays on a volume untracked.
     *
     * @throws SnapshotFilesNotDeletedException
     */
    public function deleteUnlessFilesRemain(Snapshot $snapshot): void
    {
        $remaining = $snapshot->files()->completed()->with('volume')->get();

        if ($remaining->isEmpty()) {
            $this->deleteRecord($snapshot);

            return;
        }

        $snapshot->update(['deleting' => false]);

        throw new SnapshotFilesNotDeletedException($snapshot, array_values($remaining
            ->map(fn (SnapshotFile $file): string => "{$file->volume->name}: ".($file->error ?? 'Not deleted.'))
            ->all()));
    }

    private function removeFile(SnapshotFile $file): ?string
    {
        try {
            $file->removeFromVolume();

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    private function deleteRecord(Snapshot $snapshot): void
    {
        $snapshot->skipFileCleanup = true;
        $snapshot->delete();
    }
}
