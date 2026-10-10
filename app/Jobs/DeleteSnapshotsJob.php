<?php

namespace App\Jobs;

use App\Models\Snapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeleteSnapshotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const int CHUNK_SIZE = 500;

    public int $timeout = 3600;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $snapshotIds  Already authorized by the caller.
     */
    public function __construct(
        public array $snapshotIds,
        public bool $keepFiles = false,
    ) {
        $this->onQueue('backups');
    }

    /**
     * Mark the deletable snapshots of an already authorized query as deleting
     * and queue their deletion in chunks, returning how many will be deleted.
     *
     * @param  Builder<Snapshot>  $query
     */
    public static function dispatchFor(Builder $query, bool $keepFiles): int
    {
        $snapshotIds = $query->setEagerLoads([])->deletable()
            ->get(['snapshots.id'])
            ->map(fn (Snapshot $snapshot): string => $snapshot->id)
            ->all();

        foreach (array_chunk($snapshotIds, self::CHUNK_SIZE) as $chunk) {
            Snapshot::query()->whereKey($chunk)->update(['deleting' => true]);

            try {
                self::dispatch($chunk, $keepFiles);
            } catch (Throwable $e) {
                Snapshot::query()->whereKey($chunk)->update(['deleting' => false]);

                throw $e;
            }
        }

        return count($snapshotIds);
    }

    /**
     * A snapshot locked since the dispatch is kept, and one failing deletion
     * does not stop the others. Either way the snapshot leaves the deleting
     * state, so it can be deleted again.
     */
    public function handle(): void
    {
        Snapshot::query()
            ->whereKey($this->snapshotIds)
            ->where('deleting', true)
            ->lazyById()
            ->each(function (Snapshot $snapshot): void {
                if ($snapshot->locked) {
                    $snapshot->update(['deleting' => false]);

                    return;
                }

                try {
                    $snapshot->skipFileCleanup = $this->keepFiles;
                    $snapshot->delete();
                } catch (Throwable $e) {
                    report($e);
                    $snapshot->update(['deleting' => false]);
                }
            });
    }

    public function failed(): void
    {
        Snapshot::query()
            ->whereKey($this->snapshotIds)
            ->where('deleting', true)
            ->update(['deleting' => false]);
    }
}
