<?php

namespace App\Actions\DatabaseServer;

use App\Exceptions\DatabaseServer\DatabaseServerFilesNotDeletedException;
use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\SnapshotFile;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class DeleteDatabaseServerAction
{
    use AsFake, AsObject;

    /**
     * Delete a server with its snapshots, their files on every volume unless
     * asked to leave them, and the restores that targeted it.
     *
     * The files go first, outside any transaction, so a rollback can never
     * leave rows pointing at files already deleted. When one of them cannot
     * be removed, nothing is deleted and the error is recorded on its copy:
     * a retry only finds the files still there.
     *
     * @throws DatabaseServerFilesNotDeletedException When a file is still on a volume, which keeps everything
     */
    public function handle(DatabaseServer $server, bool $keepFiles = false): void
    {
        if (! $keepFiles) {
            $this->removeFiles($server);
        }

        DB::transaction(function () use ($server) {
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
     * @throws DatabaseServerFilesNotDeletedException
     */
    private function removeFiles(DatabaseServer $server): void
    {
        $errors = [];

        $files = SnapshotFile::query()
            ->completed()
            ->whereIn('snapshot_id', $server->snapshots()->select('snapshots.id'))
            ->with(['volume', 'snapshot'])
            ->get();

        foreach ($files as $file) {
            try {
                $file->removeFromVolume();
            } catch (Throwable $e) {
                $file->update(['error' => $e->getMessage()]);
                $errors[] = "{$file->volume->name}: {$e->getMessage()}";
            }
        }

        if ($errors !== []) {
            throw new DatabaseServerFilesNotDeletedException($server, array_values(array_unique($errors)));
        }
    }
}
