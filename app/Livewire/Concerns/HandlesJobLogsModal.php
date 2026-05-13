<?php

namespace App\Livewire\Concerns;

use App\Models\BackupJob;
use Livewire\Attributes\Url;

/**
 * Shared plumbing for index pages that show a "view logs" modal for a
 * {@see BackupJob}. The consuming component must:
 *
 * - extend Livewire\Component
 * - use Illuminate\Foundation\Auth\Access\AuthorizesRequests
 * - use App\Traits\Toast (for the trait's `error` method when mount fails)
 * - define a `getSelectedJobProperty()` returning the eager-loaded BackupJob
 *   (each consumer chooses which relations to load).
 */
trait HandlesJobLogsModal
{
    public bool $showLogsModal = false;

    #[Url(as: 'job')]
    public ?string $selectedJobId = null;

    public ?string $errorMessage = null;

    public function mountHandlesJobLogsModal(): void
    {
        if (! $this->selectedJobId) {
            return;
        }

        $job = BackupJob::find($this->selectedJobId);

        if (! $job) {
            $this->errorMessage = __('Job not found: ').$this->selectedJobId;
            $this->selectedJobId = null;

            return;
        }

        $this->authorize('view', $job);

        $this->showLogsModal = true;
    }

    public function viewLogs(string $id): void
    {
        $this->selectedJobId = $id;
        $this->showLogsModal = true;
    }

    public function closeLogs(): void
    {
        $this->showLogsModal = false;
        $this->selectedJobId = null;
    }
}
