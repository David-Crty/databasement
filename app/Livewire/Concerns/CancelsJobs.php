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

    /**
     * "Server / database" a restore being cancelled writes to; null for a backup.
     */
    #[Locked]
    public ?string $cancelJobRestoreTarget = null;

    public bool $showCancelJobModal = false;

    public function confirmCancelJob(string $jobId): void
    {
        $job = BackupJob::findOrFail($jobId);

        Gate::authorize('cancel', $job);

        $this->cancelJobId = $jobId;
        $restore = $job->restore()->withoutGlobalScopes()->with(['targetServer' => fn ($query) => $query->withoutGlobalScopes()])->first();
        $this->cancelJobRestoreTarget = $restore ? "{$restore->targetServer->name} / {$restore->schema_name}" : null;
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
