<?php

namespace App\Actions\Backup;

use App\Models\Snapshot;
use App\Services\NotificationService;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class FailBackupAction
{
    use AsFake, AsObject;

    public function __construct(
        private NotificationService $notificationService,
    ) {}

    /**
     * Conclude a backup as failed, for good: no retry follows.
     */
    public function handle(Snapshot $snapshot, Throwable $exception): void
    {
        $snapshot->job->markFailed($exception);

        $this->notificationService->notifyBackupFailed($snapshot, $exception);
    }
}
