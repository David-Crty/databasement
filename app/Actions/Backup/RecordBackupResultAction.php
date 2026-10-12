<?php

namespace App\Actions\Backup;

use App\Enums\SnapshotFileStatus;
use App\Models\Snapshot;
use App\Services\Backup\DTO\BackupResult;
use App\Services\NotificationService;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RecordBackupResultAction
{
    use AsFake, AsObject;

    public function __construct(
        private NotificationService $notificationService,
    ) {}

    /**
     * Record what a backup run produced, whether or not it succeeded: the
     * archive, and which volumes it reached, so the copies that did upload
     * stay tracked. A run that produced no archive records only the volumes.
     *
     * Each volume the archive reached past its notify-only storage limit is
     * alerted here, so the alert is not lost when another volume fails the
     * backup.
     */
    public function handle(Snapshot $snapshot, BackupResult $result): void
    {
        if ($result->filename !== '') {
            $snapshot->update([
                'filename' => $result->filename,
                'file_size' => $result->fileSize,
                'checksum' => $result->checksum,
            ]);
        }

        $files = $snapshot->files()->get();

        foreach ($result->volumeResults as $volumeResult) {
            $file = $files->firstWhere('volume_id', $volumeResult->volumeId);

            if ($file === null) {
                continue;
            }

            if ($volumeResult->status === SnapshotFileStatus::Completed) {
                $file->markUploaded();
            } else {
                $file->markUploadFailed($volumeResult->error);
            }
        }

        $snapshot->unsetRelation('files');

        foreach ($result->storageWarnings() as $warning) {
            $this->notificationService->notifyStorageLimitWarning($snapshot, $warning->storageWarning ?? '', $warning->volumeName);
        }
    }
}
