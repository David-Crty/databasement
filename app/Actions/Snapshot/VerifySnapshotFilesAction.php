<?php

namespace App\Actions\Snapshot;

use App\Enums\SnapshotFileStatus;
use App\Models\Scopes\OrganizationScope;
use App\Models\Snapshot;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class VerifySnapshotFilesAction
{
    use AsFake, AsObject;

    public function __construct(
        private FilesystemProvider $filesystemProvider,
        private NotificationService $notificationService,
    ) {}

    /**
     * Check that every completed snapshot's files still exist on their volumes,
     * record the result on each file, notify once about the snapshots that
     * have just lost their last copy, and return how many did.
     */
    public function handle(?string $organizationId = null): int
    {
        $query = Snapshot::query()
            ->whereNotNull('filename')
            ->where('filename', '!=', '')
            ->completed();

        if ($organizationId) {
            $query->whereHas('databaseServer', function ($sq) use ($organizationId) {
                $sq->withoutGlobalScope(OrganizationScope::class)
                    ->whereRaw('organization_id = ?', [$organizationId]);
            });
        }

        $snapshotIds = $query->pluck('id');

        $newlyMissing = $snapshotIds
            ->map(fn (string $id) => $this->verifySnapshot($id))
            ->filter()
            ->values();

        if ($newlyMissing->isNotEmpty()) {
            $affectedServerIds = $newlyMissing->pluck('database_server_id')->unique()->values();
            $this->notificationService->notifySnapshotsMissing($newlyMissing, $affectedServerIds);
        }

        Log::info("Snapshot verification: {$snapshotIds->count()} snapshot(s) verified, {$newlyMissing->count()} newly missing.");

        return $newlyMissing->count();
    }

    /**
     * Returns the snapshot's details when it has just lost its last copy.
     *
     * @return array{server: string, database: string, filename: string, database_server_id: string}|null
     */
    private function verifySnapshot(string $snapshotId): ?array
    {
        $snapshot = Snapshot::with(['files.volume', 'databaseServer'])->find($snapshotId);

        if (! $snapshot) {
            return null;
        }

        $wasPreviouslyExisting = $snapshot->hasExistingFile();

        // Check each stored copy on its own volume; an unreachable volume only
        // stamps the copy's verification timestamp without changing its state.
        foreach ($snapshot->files as $file) {
            if ($file->status !== SnapshotFileStatus::Completed) {
                continue;
            }

            try {
                $filesystem = $this->filesystemProvider->getForVolume($file->volume);
                $exists = $filesystem->fileExists($file->storedFilename());

                $file->update([
                    'file_exists' => $exists,
                    'file_verified_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to verify snapshot file existence', [
                    'snapshot_id' => $snapshotId,
                    'volume' => $file->volume->name,
                    'filename' => $file->storedFilename(),
                    'error' => $e->getMessage(),
                ]);

                $file->update([
                    'file_verified_at' => now(),
                ]);
            }
        }

        // A snapshot only counts as newly missing once no copy is left on any
        // volume — losing one copy while another remains is not a data loss.
        if (! $wasPreviouslyExisting || $snapshot->hasExistingFile()) {
            return null;
        }

        return [
            'server' => $snapshot->databaseServer->name,
            'database' => $snapshot->database_name,
            'filename' => $snapshot->filename,
            'database_server_id' => $snapshot->database_server_id,
        ];
    }
}
