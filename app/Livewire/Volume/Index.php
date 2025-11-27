<?php

namespace App\Livewire\Volume;

use App\Models\Volume;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class Index extends Component
{
    use Toast, WithPagination;

    public string $search = '';

    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    public bool $drawer = false;

    public ?string $deleteId = null;

    public bool $showDeleteModal = false;

    public function updatingSearch()
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
        $this->reset('search');
        $this->resetPage();
        $this->success('Filters cleared.', position: 'toast-bottom');
    }

    public function headers(): array
    {
        return [
            ['key' => 'name', 'label' => __('Name'), 'class' => 'w-64'],
            ['key' => 'type', 'label' => __('Type'), 'class' => 'w-32'],
            ['key' => 'config', 'label' => __('Configuration'), 'sortable' => false],
            ['key' => 'created_at', 'label' => __('Created'), 'class' => 'w-40'],
        ];
    }

    public function confirmDelete(string $id)
    {
        $this->deleteId = $id;
        $this->showDeleteModal = true;
    }

    public function delete()
    {
        if ($this->deleteId) {
            Volume::findOrFail($this->deleteId)->delete();
            $this->deleteId = null;

            $this->success('Volume deleted successfully!', position: 'toast-bottom');
            $this->showDeleteModal = false;
        }
    }

    public function render()
    {
        $volumes = Volume::query()
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('type', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy($this->sortBy['column'], $this->sortBy['direction'])
            ->paginate(10);

        return view('livewire.volume.index', [
            'volumes' => $volumes,
            'headers' => $this->headers(),
        ])->layout('components.layouts.app', ['title' => __('Volumes')]);
    }
}
