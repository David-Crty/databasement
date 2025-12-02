<?php

namespace App\Livewire\Job;

use App\Models\BackupJob;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class Index extends Component
{
    use Toast, WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

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
        $jobs = BackupJob::query()
            ->with([
                'snapshot.databaseServer',
                'snapshot.triggeredBy',
                'restore.snapshot.databaseServer',
                'restore.targetServer',
                'restore.triggeredBy',
            ])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    // Search in snapshot-related data
                    $q->whereHas('snapshot.databaseServer', function ($sq) {
                        $sq->where('name', 'like', '%'.$this->search.'%');
                    })
                        ->orWhereHas('snapshot', function ($sq) {
                            $sq->where('database_name', 'like', '%'.$this->search.'%')
                                ->orWhere('database_host', 'like', '%'.$this->search.'%');
                        })
                        // Search in restore-related data
                        ->orWhereHas('restore.targetServer', function ($sq) {
                            $sq->where('name', 'like', '%'.$this->search.'%');
                        })
                        ->orWhereHas('restore', function ($sq) {
                            $sq->where('schema_name', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->when($this->statusFilter !== 'all', function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->when($this->typeFilter !== 'all', function ($query) {
                if ($this->typeFilter === 'backup') {
                    $query->whereHas('snapshot');
                } else {
                    $query->whereHas('restore');
                }
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate(15);

        return view('livewire.job.index', [
            'jobs' => $jobs,
            'headers' => $this->headers(),
            'statusOptions' => $this->statusOptions(),
            'typeOptions' => $this->typeOptions(),
        ])->layout('components.layouts.app', ['title' => __('Jobs')]);
    }
}
