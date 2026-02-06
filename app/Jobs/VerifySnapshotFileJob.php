<?php

namespace App\Jobs;

use App\Models\Snapshot;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\FailureNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class VerifySnapshotFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public ?string $snapshotId = null
    ) {
        $this->onQueue('backups');
    }

    /** @var Collection<int, array{server: string, database: string, filename: string}> */
    private Collection $newlyMissing;

    public function handle(FilesystemProvider $filesystemProvider, FailureNotificationService $notificationService): void
    {
        $this->newlyMissing = collect();

        if ($this->snapshotId) {
            $this->verifySnapshot($filesystemProvider, $this->snapshotId);

            return;
        }

        $snapshotIds = Snapshot::query()
            ->whereNotNull('filename')
            ->where('filename', '!=', '')
            ->whereRelation('job', 'status', 'completed')
            ->pluck('id');

        foreach ($snapshotIds as $id) {
            $this->verifySnapshot($filesystemProvider, $id);
        }

        if ($this->newlyMissing->isNotEmpty()) {
            $notificationService->notifySnapshotsMissing($this->newlyMissing);
        }
    }

    private function verifySnapshot(FilesystemProvider $filesystemProvider, string $snapshotId): void
    {
        $snapshot = Snapshot::with(['volume', 'databaseServer'])->find($snapshotId);

        if (! $snapshot) {
            return;
        }

        try {
            $filesystem = $filesystemProvider->getForVolume($snapshot->volume);
            $exists = $filesystem->fileExists($snapshot->filename);

            $wasPreviouslyExisting = $snapshot->file_exists;

            $snapshot->update([
                'file_exists' => $exists,
                'file_verified_at' => now(),
            ]);

            if (! $exists && $wasPreviouslyExisting) {
                $this->newlyMissing->push([
                    'server' => $snapshot->databaseServer->name ?? 'Unknown',
                    'database' => $snapshot->database_name,
                    'filename' => $snapshot->filename,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to verify snapshot file existence', [
                'snapshot_id' => $snapshotId,
                'filename' => $snapshot->filename,
                'error' => $e->getMessage(),
            ]);

            // On transient errors, update verified_at but leave file_exists unchanged
            $snapshot->update([
                'file_verified_at' => now(),
            ]);
        }
    }
}
