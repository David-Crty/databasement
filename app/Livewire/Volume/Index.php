<?php

namespace App\Livewire\Volume;

use App\Models\Volume;
use App\Queries\VolumeQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

class Index extends Component
{
    use AuthorizesRequests, Toast, WithPagination;

    #[Url]
    public string $search = '';

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'created_at', 'direction' => 'desc'];

    #[Locked]
    public ?string $deleteId = null;

    public bool $showDeleteModal = false;

    public int $deleteSnapshotCount = 0;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @param  string|array<string, mixed>  $property
     */
    public function updated(string|array $property): void
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function headers(): array
    {
        return [
            ['key' => 'name', 'label' => __('Name'), 'class' => 'w-64'],
            ['key' => 'type', 'label' => __('Type'), 'class' => 'w-32'],
            ['key' => 'config', 'label' => __('Configuration'), 'sortable' => false],
            ['key' => 'created_at', 'label' => __('Created'), 'class' => 'w-40'],
        ];
    }

    public function confirmDelete(string $id): void
    {
        $volume = Volume::findOrFail($id);

        $this->authorize('delete', $volume);

        $this->deleteId = $id;
        $this->deleteSnapshotCount = $volume->snapshots()->count();
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $volume = Volume::findOrFail($this->deleteId);

        $this->authorize('delete', $volume);

        $volume->delete();
        $this->deleteId = null;
        $this->showDeleteModal = false;

        $this->success('Volume deleted successfully!', position: 'toast-bottom');
    }

    public function render(): View
    {
        $volumes = VolumeQuery::buildFromParams(
            search: $this->search,
            sortColumn: $this->sortBy['column'],
            sortDirection: $this->sortBy['direction']
        )->paginate(10);

        return view('livewire.volume.index', [
            'volumes' => $volumes,
            'headers' => $this->headers(),
        ])->layout('components.layouts.app', ['title' => __('Volumes')]);
    }
}
