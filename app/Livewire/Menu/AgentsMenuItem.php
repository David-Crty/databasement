<?php

namespace App\Livewire\Menu;

use App\Models\Agent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class AgentsMenuItem extends Component
{
    public bool $isActive = false;

    public function mount(): void
    {
        $this->isActive = request()->routeIs('agents.*');
    }

    /**
     * @return Collection<int, Agent>
     */
    #[Computed]
    public function outdatedAgents(): Collection
    {
        return Agent::query()
            ->whereNotNull('last_heartbeat_at')
            ->orderBy('name')
            ->get(['id', 'name', 'last_heartbeat_at', 'version', 'commit_hash'])
            ->filter(fn (Agent $agent) => $agent->needsUpdate())
            ->values();
    }

    #[Computed]
    public function serverVersion(): ?string
    {
        return Agent::serverVersion();
    }

    public function render(): View
    {
        return view('livewire.menu.agents-menu-item');
    }
}
