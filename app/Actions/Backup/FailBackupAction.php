<?php

namespace App\Actions\Backup;

use App\Enums\BackupJobStatus;
use App\Exceptions\Backup\JobCancelledException;
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
     * Conclude a backup as failed, for good, and notify it once.
     *
     * A job already failed with this same exception (a queue job's own catch,
     * before its retries ran out) is notified now. One something else already
     * failed, and notified, is left alone.
     *
     * @return bool Whether the failure was notified
     *
     * @throws JobCancelledException When the job was cancelled, which is not notified
     */
    public function handle(Snapshot $snapshot, Throwable $exception): bool
    {
        $job = $snapshot->job;

        if ($job->status !== BackupJobStatus::Failed) {
            $job->markFailed($exception);
        } elseif ($job->error_message !== $exception->getMessage()) {
            return false;
        }

        $this->notificationService->notifyBackupFailed($snapshot, $exception);

        return true;
    }
}
