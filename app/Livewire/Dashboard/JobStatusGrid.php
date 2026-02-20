<?php

namespace App\Livewire\Dashboard;

use App\Models\BackupJob;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Lazy]
class JobStatusGrid extends Component
{
    public bool $showLogsModal = false;

    public ?string $selectedJobId = null;

    /**
     * @return Collection<int, BackupJob>
     */
    #[Computed]
    public function jobs(): Collection
    {
        return BackupJob::query()
            ->select(['id', 'status', 'duration_ms', 'created_at'])
            ->with([
                'snapshot:id,backup_job_id,database_name,database_server_id' => [
                    'databaseServer:id,name',
                ],
                'restore:id,backup_job_id,target_server_id,snapshot_id' => [
                    'targetServer:id,name',
                    'snapshot:id,database_name,database_server_id' => [
                        'databaseServer:id,name',
                    ],
                ],
            ])
            ->where('created_at', '>=', now()->subDays(30))
            ->latest('created_at')
            ->limit(1000)
            ->get();
    }

    #[On('refresh-dashboard')]
    public function refreshJobs(): void
    {
        unset($this->jobs);
    }

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
        return view('livewire.dashboard.job-status-grid');
    }
}
