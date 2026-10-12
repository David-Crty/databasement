<?php

namespace App\Actions\Restore;

use App\Models\Restore;
use App\Services\NotificationService;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class FailRestoreAction
{
    use AsFake, AsObject;

    public function __construct(
        private NotificationService $notificationService,
    ) {}

    /**
     * Conclude a restore as failed, for good: no retry follows.
     */
    public function handle(Restore $restore, Throwable $exception): void
    {
        $restore->job->markFailed($exception);

        $this->notificationService->notifyRestoreFailed($restore, $exception);
    }
}
