<?php

namespace App\Actions\Volume;

use App\Exceptions\Volume\VolumeFilesNotDeletedException;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Models\Volume;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteVolumeAction
{
    use AsFake, AsObject;

    /**
     * Delete a volume with its copies of snapshot files, the snapshots left
     * without any copy and the backup configurations that only targeted it.
     * Snapshots with a copy on another volume survive.
     *
     * The files go first, outside any transaction, so a rollback can never
     * leave rows pointing at files already deleted. When one of them cannot
     * be removed, nothing is deleted and the error is recorded on its copy:
     * a retry only finds the files still there. Nothing is deleted either
     * when a backup completed meanwhile, as its file was not removed.
     *
     * @throws VolumeFilesNotDeletedException When a file is still on the volume, which keeps everything
     */
    public function handle(Volume $volume, bool $keepFiles = false): void
    {
        $removedFileIds = $keepFiles ? null : $this->removeFiles($volume);

        DB::transaction(function () use ($volume, $removedFileIds) {
            if ($removedFileIds !== null && $volume->snapshotFiles()->completed()->whereKeyNot($removedFileIds)->exists()) {
                throw new VolumeFilesNotDeletedException($volume, ['a backup completed while it was being deleted']);
            }

            $snapshotIds = $volume->snapshotFiles()->distinct()->pluck('snapshot_id')->all();

            $volume->snapshotFiles()->delete();

            $orphanedSnapshots = Snapshot::query()->whereKey($snapshotIds)->whereDoesntHave('files')->get();

            foreach ($orphanedSnapshots as $snapshot) {
                $snapshot->delete();
            }

            foreach ($volume->backups()->with('volumes:id')->get() as $backup) {
                if (! $backup->volumes->contains(fn (Volume $other) => $other->id !== $volume->id)) {
                    $backup->delete();
                }
            }

            $volume->delete();
        });
    }

    /**
     * @return list<int|string> The ids of the copies whose file was removed
     *
     * @throws VolumeFilesNotDeletedException
     */
    private function removeFiles(Volume $volume): array
    {
        $files = $volume->snapshotFiles()->completed()->with('volume')->get();
        $left = SnapshotFile::removeEachFromVolume($files);

        if ($left->isNotEmpty()) {
            $errors = $left->map(fn (SnapshotFile $file): string => $file->error ?? '')->all();

            throw new VolumeFilesNotDeletedException($volume, array_values(array_unique($errors)));
        }

        return array_values($files->modelKeys());
    }
}
