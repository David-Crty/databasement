<?php

namespace App\Livewire\Concerns;

use App\Models\BackupJob;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * Cancel a backup or restore job in progress, behind a confirmation modal
 * rendered by the job logs modal partial. The consuming component must use
 * {@see \App\Traits\Toast}.
 */
trait CancelsJobs
{
    #[Locked]
    public ?string $cancelJobId = null;

    #[Locked]
    public bool $cancelJobIsRestore = false;

    public bool $showCancelJobModal = false;

    public function confirmCancelJob(string $jobId): void
    {
        $job = BackupJob::findOrFail($jobId);

        Gate::authorize('cancel', $job);

        $this->cancelJobId = $jobId;
        $this->cancelJobIsRestore = $job->restore()->withoutGlobalScopes()->exists();
        $this->showCancelJobModal = true;
    }

    public function cancelJob(): void
    {
        if (! $this->cancelJobId) {
            return;
        }

        $job = BackupJob::findOrFail($this->cancelJobId);

        $this->cancelJobId = null;
        $this->showCancelJobModal = false;

        if ($job->status->isInProgress()) {
            Gate::authorize('cancel', $job);

            /** @var User $user */
            $user = auth()->user();

            if ($job->cancel($user->name)) {
                $this->success(__('Job cancelled.'));

                return;
            }
        }

        $this->error(__('The job already finished.'));
    }
}
