<?php

namespace App\Actions\DatabaseServer;

use App\Exceptions\DatabaseServer\DatabaseServerFilesNotDeletedException;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\SnapshotFile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteDatabaseServerAction
{
    use AsFake, AsObject;

    /**
     * Delete a server with its snapshots, their files on every volume unless
     * asked to leave them, and the restores that targeted it.
     *
     * The files go first, outside any transaction, so a rollback can never
     * leave rows pointing at files already deleted. When one of them cannot
     * be removed, no row is deleted: the copies whose file did go are marked
     * as no longer existing, the error is recorded on the others, and a retry
     * only finds those files still there. No row is deleted either when a
     * backup completed meanwhile, as its file was not removed.
     *
     * @throws DatabaseServerFilesNotDeletedException When a file is still on a volume, which keeps every row
     */
    public function handle(DatabaseServer $server, bool $keepFiles = false): void
    {
        $removedFileIds = $keepFiles ? null : $this->removeFiles($server);

        DB::transaction(function () use ($server, $removedFileIds) {
            if ($removedFileIds !== null && $this->completedFiles($server)->whereKeyNot($removedFileIds)->exists()) {
                throw new DatabaseServerFilesNotDeletedException($server, ['a backup completed while it was being deleted']);
            }

            foreach ($server->snapshots()->get() as $snapshot) {
                $snapshot->delete();
            }

            foreach (Restore::where('target_server_id', $server->id)->get() as $restore) {
                $restore->delete();
            }

            $server->delete();
        });
    }

    /**
     * @return list<int|string> The ids of the copies whose file was removed
     *
     * @throws DatabaseServerFilesNotDeletedException
     */
    private function removeFiles(DatabaseServer $server): array
    {
        $files = $this->completedFiles($server)->with(['volume', 'snapshot'])->get();
        $left = SnapshotFile::removeEachFromVolume($files);

        if ($left->isNotEmpty()) {
            $errors = $left->map(fn (SnapshotFile $file): string => "{$file->volume->name}: {$file->error}")->all();

            throw new DatabaseServerFilesNotDeletedException($server, array_values(array_unique($errors)));
        }

        return array_values($files->modelKeys());
    }

    /**
     * @return Builder<SnapshotFile>
     */
    private function completedFiles(DatabaseServer $server): Builder
    {
        return SnapshotFile::query()
            ->completed()
            ->whereIn('snapshot_id', $server->snapshots()->select('snapshots.id'));
    }
}
