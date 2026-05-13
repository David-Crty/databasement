<?php

namespace App\Livewire\Concerns;

use App\Models\BackupJob;
use Livewire\Attributes\Url;

/**
 * Shared plumbing for index pages that show a "view logs" modal for a
 * {@see BackupJob}. The consuming component must:
 *
 * - extend {@see \Livewire\Component}
 * - use {@see \Illuminate\Foundation\Auth\Access\AuthorizesRequests}
 * - define a `getSelectedJobProperty()` returning the eager-loaded BackupJob
 *   (each consumer chooses which relations to load).
 *
 * When the `?job=ID` URL parameter resolves to an unknown job, the trait
 * exposes the message via `$errorMessage` (the host's blade is expected to
 * render it) rather than throwing — this preserves a usable index page when
 * a stale notification link is followed.
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
