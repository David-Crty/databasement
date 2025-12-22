<?php

namespace App\Livewire\BackupJob;

use App\Models\BackupJob;
use App\Queries\BackupJobQuery;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class Index extends Component
{
    use Toast, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = 'all';

    #[Url]
    public string $typeFilter = 'all';

    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    public bool $drawer = false;

    public bool $showLogsModal = false;

    public ?string $selectedJobId = null;

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingStatusFilter()
    {
        $this->resetPage();
    }

    public function updatingTypeFilter()
    {
        $this->resetPage();
    }

    public function updated($property): void
    {
        if (! is_array($property) && $property != '') {
            $this->resetPage();
        }
    }

    public function clear(): void
    {
        $this->reset('search', 'statusFilter', 'typeFilter');
        $this->resetPage();
        $this->success('Filters cleared.', position: 'toast-bottom');
    }

    public function headers(): array
    {
        return [
            ['key' => 'type', 'label' => __('Type'), 'sortable' => false, 'class' => 'w-32'],
            ['key' => 'created_at', 'label' => __('Created'), 'class' => 'w-48'],
            ['key' => 'server', 'label' => __('Server / Database'), 'sortable' => false],
            ['key' => 'status', 'label' => __('Status'), 'sortable' => false, 'class' => 'w-32'],
            ['key' => 'duration', 'label' => __('Duration'), 'sortable' => false, 'class' => 'w-32'],
            ['key' => 'triggered_by', 'label' => __('User'), 'sortable' => false, 'class' => 'w-40'],
        ];
    }

    public function viewLogs(string $id): void
    {
        $this->selectedJobId = $id;
        $this->showLogsModal = true;
    }

    public function getSelectedJobProperty()
    {
        if (! $this->selectedJobId) {
            return null;
        }

        return BackupJob::with(['snapshot.databaseServer', 'snapshot.triggeredBy', 'restore.snapshot.databaseServer', 'restore.targetServer', 'restore.triggeredBy'])
            ->find($this->selectedJobId);
    }

    public function statusOptions(): array
    {
        return [
            ['id' => 'all', 'name' => __('All Statuses')],
            ['id' => 'completed', 'name' => __('Completed')],
            ['id' => 'failed', 'name' => __('Failed')],
            ['id' => 'running', 'name' => __('Running')],
            ['id' => 'pending', 'name' => __('Pending')],
            ['id' => 'queued', 'name' => __('Queued')],
        ];
    }

    public function typeOptions(): array
    {
        return [
            ['id' => 'all', 'name' => __('All Types')],
            ['id' => 'backup', 'name' => __('Backup')],
            ['id' => 'restore', 'name' => __('Restore')],
        ];
    }

    public function render()
    {
        $jobs = BackupJobQuery::buildFromParams(
            search: $this->search,
            statusFilter: $this->statusFilter,
            typeFilter: $this->typeFilter,
            sortColumn: $this->sortBy['column'],
            sortDirection: $this->sortBy['direction']
        )->paginate(15);

        return view('livewire.backup-job.index', [
            'jobs' => $jobs,
            'headers' => $this->headers(),
            'statusOptions' => $this->statusOptions(),
            'typeOptions' => $this->typeOptions(),
        ])->layout('components.layouts.app', ['title' => __('Jobs')]);
    }
}
