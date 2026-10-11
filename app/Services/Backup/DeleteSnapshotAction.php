<?php

namespace App\Services\Backup;

use App\Exceptions\Backup\SnapshotFilesNotDeletedException;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use Throwable;

class DeleteSnapshotAction
{
    /**
     * Delete a snapshot with its file on every volume, or leave the files in
     * place when asked to.
     *
     * @throws SnapshotFilesNotDeletedException When a file could not be removed, which keeps the snapshot
     */
    public function execute(Snapshot $snapshot, bool $keepFiles = false): void
    {
        if ($keepFiles) {
            $this->deleteRecord($snapshot);

            return;
        }

        $this->recordRemovals($snapshot, $snapshot->files()->completed()->with('volume')->get()
            ->mapWithKeys(fn (SnapshotFile $file): array => [$file->id => $this->removeFile($file)])
            ->all());

        $this->deleteUnlessFilesRemain($snapshot);
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

        throw new SnapshotFilesNotDeletedException($snapshot, $remaining
            ->mapWithKeys(fn (SnapshotFile $file): array => [$file->volume->name => $file->error ?? 'Not deleted.'])
            ->all());
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
