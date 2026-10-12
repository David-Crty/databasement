<?php

namespace App\Actions\Restore;

use App\Enums\BackupJobStatus;
use App\Exceptions\Backup\JobCancelledException;
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
     * Conclude a restore as failed, for good, and notify it once.
     *
     * A job already failed with this same exception (a queue job's own catch,
     * before its retries ran out) is notified now. One something else already
     * failed, and notified, is left alone.
     *
     * @return bool Whether the failure was notified
     *
     * @throws JobCancelledException When the job was cancelled, which is not notified
     */
    public function handle(Restore $restore, Throwable $exception): bool
    {
        $job = $restore->job;

        if ($job->status !== BackupJobStatus::Failed) {
            $job->markFailed($exception);
        } elseif ($job->error_message !== $exception->getMessage()) {
            return false;
        }

        $this->notificationService->notifyRestoreFailed($restore, $exception);

        return true;
    }
}
