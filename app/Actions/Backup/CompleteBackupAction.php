<?php

namespace App\Actions\Backup;

use App\Models\Snapshot;
use App\Services\Backup\DTO\BackupResult;
use App\Services\NotificationService;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class CompleteBackupAction
{
    use AsFake, AsObject;

    public function __construct(
        private NotificationService $notificationService,
    ) {}

    /**
     * Conclude a backup whose every copy uploaded: complete its job, notify
     * the success, and alert about each volume past its notify-only storage
     * limit.
     */
    public function handle(Snapshot $snapshot, BackupResult $result): void
    {
        $snapshot->job->markCompleted();

        $this->notificationService->notifyBackupSuccess($snapshot);

        foreach ($result->storageWarnings() as $warning) {
            $this->notificationService->notifyStorageLimitWarning($snapshot, $warning->storageWarning ?? '', $warning->volumeName);
        }
    }
}
