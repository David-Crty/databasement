<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\WithDeferredLoading;
use App\Models\BackupJob;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class LatestJobs extends Component
{
    use WithDeferredLoading;

    public string $statusFilter = 'all';

    /** @var Collection<int, BackupJob> */
    public Collection $jobs;

    public function mount(): void
    {
        $this->jobs = new Collection;
    }

    protected function loadContent(): void
    {
        $this->fetchJobs();
    }

    public function updatedStatusFilter(): void
    {
        $this->fetchJobs();
    }

    public function fetchJobs(): void
    {
        $query = BackupJob::query()
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
