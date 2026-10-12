<?php

namespace App\Actions\Backup;

use App\Models\Snapshot;
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
     * Conclude a backup whose every copy uploaded: complete its job and
     * notify the success.
     */
    public function handle(Snapshot $snapshot): void
    {
        $snapshot->job->markCompleted();

        $this->notificationService->notifyBackupSuccess($snapshot);
    }
}
