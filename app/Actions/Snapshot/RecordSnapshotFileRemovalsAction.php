<?php

namespace App\Actions\Snapshot;

use App\Models\Snapshot;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RecordSnapshotFileRemovalsAction
{
    use AsFake, AsObject;

    /**
     * Forget the copies whose file is gone, so a retry only revisits the
     * others, and record on each of those why its file is still there.
     *
     * @param  array<string, string|null>  $outcomes  Per copy id: null once its file is gone, the error otherwise
     */
    public function handle(Snapshot $snapshot, array $outcomes): void
    {
        foreach ($snapshot->files()->completed()->whereKey(array_keys($outcomes))->get() as $file) {
            if ($outcomes[$file->id] === null) {
                $file->delete();
            } else {
                $file->update(['error' => $outcomes[$file->id]]);
            }
        }
    }
}
