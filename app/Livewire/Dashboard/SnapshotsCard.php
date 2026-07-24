<?php

namespace App\Livewire\Dashboard;

use App\Models\Snapshot;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Lazy]
class SnapshotsCard extends Component
{
    public int $totalSnapshots = 0;

    public int $verifiedSnapshots = 0;

    public int $missingSnapshots = 0;

    public function mount(): void
    {
        $this->loadData();
    }

    #[On('refresh-dashboard')]
    public function refreshDashboard(): void
    {
        $this->loadData();
    }

    private function loadData(): void
    {
        $baseQuery = Snapshot::forCurrentOrg()->completed();

        $this->totalSnapshots = $baseQuery->count();
        $this->verifiedSnapshots = (clone $baseQuery)->whereNotNull('file_verified_at')->count();
        $this->missingSnapshots = (clone $baseQuery)->fileMissing()->count();
    }

    public function placeholder(): View
    {
        return view('components.lazy-placeholder', ['type' => 'stats']);
    }

    public function render(): View
    {
        return view('livewire.dashboard.snapshots-card');
    }
}
