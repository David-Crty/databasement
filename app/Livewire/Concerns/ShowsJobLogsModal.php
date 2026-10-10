<?php

namespace App\Livewire\Concerns;

use App\Models\BackupJob;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The "view logs" modal for a widget embedded in another page, such as the
 * dashboard. Unlike {@see HandlesJobLogsModal} it has no `?job=` deep link,
 * so the id is locked and the job is read through the current organization.
 */
trait ShowsJobLogsModal
{
    public bool $showLogsModal = false;

    #[Locked]
    public ?string $selectedJobId = null;

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

    #[Computed]
    public function selectedJob(): ?BackupJob
    {
        if (! $this->selectedJobId) {
            return null;
        }

        return BackupJob::forCurrentOrg()->with([
            'snapshot.databaseServer',
            'snapshot.triggeredBy',
            'restore.snapshot.databaseServer',
            'restore.targetServer',
            'restore.triggeredBy',
        ])->find($this->selectedJobId);
    }
}
