<?php

namespace App\Livewire\Dashboard;

use App\Models\BackupJob;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class JobStatusGrid extends Component
{
    public bool $showLogsModal = false;

    public ?string $selectedJobId = null;

    public function placeholder(): View
    {
        return view('components.lazy-placeholder', ['type' => 'stats']);
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

    #[Computed]
    public function selectedJob(): ?BackupJob
    {
        if (! $this->selectedJobId) {
            return null;
        }

        return BackupJob::with([
            'snapshot.databaseServer',
            'snapshot.triggeredBy',
            'restore.snapshot.databaseServer',
            'restore.targetServer',
            'restore.triggeredBy',
        ])->find($this->selectedJobId);
    }

    public function render(): View
    {
        $jobs = BackupJob::query()
            ->with([
                'snapshot.databaseServer',
                'restore.targetServer',
                'restore.snapshot.databaseServer',
            ])
            ->where('created_at', '>=', now()->subDays(30))
            ->latest('created_at')
            ->get();

        return view('livewire.dashboard.job-status-grid', [
            'jobs' => $jobs,
        ]);
    }
}
