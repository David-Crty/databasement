<?php

namespace App\Livewire\Dashboard;

use App\Models\BackupJob;
use App\Queries\BackupJobQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

#[Lazy]
class LatestJobs extends Component
{
    public string $statusFilter = 'all';

    /** @var Collection<int, BackupJob> */
    public Collection $jobs;

    public bool $showLogsModal = false;

    #[Locked]
    public ?string $selectedJobId = null;

    #[Locked]
    public ?string $serverId = null;

    public function mount(?string $serverId = null): void
    {
        $this->serverId = $serverId;
        $this->jobs = new Collection;
        $this->fetchJobs();
    }

    #[On('refresh-dashboard')]
    public function refreshDashboard(): void
    {
        $this->fetchJobs();
    }

    public function placeholder(): View
    {
        return view('components.lazy-placeholder', ['type' => 'list']);
    }

    public function updatedStatusFilter(): void
    {
        $this->fetchJobs();
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

    public function getSelectedJobProperty(): ?BackupJob
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

    public function fetchJobs(): void
    {
        $query = BackupJob::forCurrentOrg()
            ->with([
                'snapshot.databaseServer',
                'restore.targetServer',
                'restore.snapshot.databaseServer',
            ])
            ->orderByRaw("CASE WHEN status = 'running' THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'desc')
            ->limit(12);

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->serverId !== null) {
            BackupJobQuery::applyServerFilter($query, $this->serverId);
        }

        $this->jobs = $query->get();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function statusOptions(): array
    {
        return [
            ['id' => 'all', 'name' => __('All')],
            ['id' => 'running', 'name' => __('Running')],
            ['id' => 'failed', 'name' => __('Failed')],
            ['id' => 'completed', 'name' => __('Completed')],
            ['id' => 'pending', 'name' => __('Pending')],
        ];
    }

    public function render(): View
    {
        return view('livewire.dashboard.latest-jobs', [
            'statusOptions' => $this->statusOptions(),
        ]);
    }
}
