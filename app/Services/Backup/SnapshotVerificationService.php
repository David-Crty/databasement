<?php

namespace App\Services\Backup;

use App\Models\Snapshot;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\FailureNotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SnapshotVerificationService
{
    /** @var Collection<int, array{server: string, database: string, filename: string}> */
    private Collection $newlyMissing;

    public function __construct(
        private FilesystemProvider $filesystemProvider,
        private FailureNotificationService $notificationService
    ) {}

    /**
     * Verify all completed snapshots still exist on their storage volumes.
     *
     * @return array{verified: int, missing: int}
     */
    public function run(): array
    {
        $this->newlyMissing = collect();
        $verified = 0;

        $snapshotIds = Snapshot::query()
            ->whereNotNull('filename')
            ->where('filename', '!=', '')
            ->whereRelation('job', 'status', 'completed')
            ->pluck('id');

        foreach ($snapshotIds as $id) {
            $this->verifySnapshot($id);
            $verified++;
        }

        if ($this->newlyMissing->isNotEmpty()) {
            $this->notificationService->notifySnapshotsMissing($this->newlyMissing);
        }

        Log::info("Snapshot verification: {$verified} snapshot(s) verified, {$this->newlyMissing->count()} newly missing.");

        return ['verified' => $verified, 'missing' => $this->newlyMissing->count()];
    }

    private function verifySnapshot(string $snapshotId): void
    {
        $snapshot = Snapshot::with(['volume', 'databaseServer'])->find($snapshotId);

        if (! $snapshot) {
            return;
        }

        try {
            $filesystem = $this->filesystemProvider->getForVolume($snapshot->volume);
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
        } catch (\Throwable $e) {
            Log::warning('Failed to verify snapshot file existence', [
                'snapshot_id' => $snapshotId,
                'filename' => $snapshot->filename,
                'error' => $e->getMessage(),
            ]);

            $snapshot->update([
                'file_verified_at' => now(),
            ]);
        }
    }
}
