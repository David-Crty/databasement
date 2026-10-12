<?php

namespace App\Actions\Snapshot;

use App\Jobs\DeleteSnapshotsJob;
use App\Models\Snapshot;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class DispatchSnapshotDeletionAction
{
    use AsFake, AsObject;

    private const int CHUNK_SIZE = 500;

    /**
     * Mark the deletable snapshots of an already authorized query as deleting
     * and queue their deletion in chunks.
     *
     * @param  Builder<Snapshot>  $query
     * @return int How many snapshots will be deleted
     */
    public function handle(Builder $query, bool $keepFiles = false): int
    {
        $snapshotIds = $query->setEagerLoads([])->deletable()
            ->get(['snapshots.id'])
            ->map(fn (Snapshot $snapshot): string => $snapshot->id)
            ->all();

        foreach (array_chunk($snapshotIds, self::CHUNK_SIZE) as $chunk) {
            Snapshot::query()->whereKey($chunk)->update(['deleting' => true]);

            try {
                DeleteSnapshotsJob::dispatch($chunk, $keepFiles);
            } catch (Throwable $e) {
                Snapshot::query()->whereKey($chunk)->update(['deleting' => false]);

                throw $e;
            }
        }

        return count($snapshotIds);
    }
}
