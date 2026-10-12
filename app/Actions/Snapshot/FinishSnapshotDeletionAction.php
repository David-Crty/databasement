<?php

namespace App\Actions\Snapshot;

use App\Exceptions\Backup\SnapshotFilesNotDeletedException;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class FinishSnapshotDeletionAction
{
    use AsFake, AsObject;

    /**
     * Apply the reported removals, then delete the snapshot once no uploaded
     * copy is left. Otherwise keep it, so no file stays on a volume untracked.
     *
     * @param  array<string, string|null>  $outcomes  Per copy id: null once its file is gone, the error otherwise
     *
     * @throws SnapshotFilesNotDeletedException When a copy is left, which keeps the snapshot
     */
    public function handle(Snapshot $snapshot, array $outcomes): void
    {
        RecordSnapshotFileRemovalsAction::run($snapshot, $outcomes);

        $remaining = $snapshot->files()->completed()->with('volume')->get();

        if ($remaining->isEmpty()) {
            $snapshot->skipFileCleanup = true;
            $snapshot->delete();

            return;
        }

        $snapshot->update(['deleting' => false]);

        throw new SnapshotFilesNotDeletedException($snapshot, array_values($remaining
            ->map(fn (SnapshotFile $file): string => "{$file->volume->name}: ".($file->error ?? 'Not deleted.'))
            ->all()));
    }
}
