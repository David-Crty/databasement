<?php

namespace App\Livewire\Agent;

use App\Models\Agent;
use App\Queries\AgentQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

#[Title('Agents')]
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

    public int $deleteServerCount = 0;

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
            ['key' => 'status', 'label' => __('Status'), 'class' => 'w-32', 'sortable' => false],
            ['key' => 'servers', 'label' => __('Servers'), 'class' => 'w-32', 'sortable' => false],
            ['key' => 'last_heartbeat_at', 'label' => __('Last Heartbeat'), 'class' => 'w-48'],
            ['key' => 'created_at', 'label' => __('Created'), 'class' => 'w-40'],
        ];
    }

    public function confirmDelete(string $id): void
    {
        $agent = Agent::findOrFail($id);

        $this->authorize('delete', $agent);

        $this->deleteId = $id;
        $this->deleteServerCount = $agent->databaseServers()->count();
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $agent = Agent::findOrFail($this->deleteId);

        $this->authorize('delete', $agent);

        $agent->delete();
        $this->deleteId = null;
        $this->showDeleteModal = false;

        $this->success('Agent deleted successfully!', position: 'toast-bottom');
    }

    public function render(): View
    {
        $query = Agent::query()
            ->withCount('databaseServers');

        if ($this->search) {
            $query->where('name', 'like', "%{$this->search}%");
        }

        AgentQuery::applySort($query, $this->sortBy);

        return view('livewire.agent.index', [
            'agents' => $query->paginate(10),
            'headers' => $this->headers(),
        ]);
    }
}
