<?php

namespace App\Jobs;

use App\Actions\Snapshot\DeleteSnapshotAction;
use App\Models\Snapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeleteSnapshotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
                    DeleteSnapshotAction::run($snapshot, $this->keepFiles);
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
