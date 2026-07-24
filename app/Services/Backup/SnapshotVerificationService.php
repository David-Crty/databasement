<?php

namespace App\Services\Backup;

use App\Enums\SnapshotFileStatus;
use App\Models\Scopes\OrganizationScope;
use App\Models\Snapshot;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Services\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SnapshotVerificationService
{
    /** @var Collection<int, array{server: string, database: string, filename: string, database_server_id: string}> */
    private Collection $newlyMissing;

    public function __construct(
        private FilesystemProvider $filesystemProvider,
        private NotificationService $notificationService
    ) {}

    /**
     * Verify completed snapshots still exist on their storage volumes.
     *
     * @return array{verified: int, missing: int}
     */
    public function run(?string $organizationId = null): array
    {
        $this->newlyMissing = collect();
        $verified = 0;

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

        foreach ($snapshotIds as $id) {
            $this->verifySnapshot($id);
            $verified++;
        }

        if ($this->newlyMissing->isNotEmpty()) {
            $affectedServerIds = $this->newlyMissing->pluck('database_server_id')->unique()->values();
            $this->notificationService->notifySnapshotsMissing($this->newlyMissing, $affectedServerIds);
        }

        Log::info("Snapshot verification: {$verified} snapshot(s) verified, {$this->newlyMissing->count()} newly missing.");

        return ['verified' => $verified, 'missing' => $this->newlyMissing->count()];
    }

    private function verifySnapshot(string $snapshotId): void
    {
        $snapshot = Snapshot::with(['files.volume', 'databaseServer'])->find($snapshotId);

        if (! $snapshot) {
            return;
        }

        $wasPreviouslyExisting = $snapshot->file_exists;

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

        $snapshot->update(['file_verified_at' => now()]);
        $snapshot->recomputeFileExists();

        // A snapshot only counts as newly missing once no copy is left on any
        // volume — losing one copy while another remains is not a data loss.
        if ($wasPreviouslyExisting && ! $snapshot->file_exists) {
            $this->newlyMissing->push([
                'server' => $snapshot->databaseServer->name,
                'database' => $snapshot->database_name,
                'filename' => $snapshot->filename,
                'database_server_id' => $snapshot->database_server_id,
            ]);
        }
    }
}
