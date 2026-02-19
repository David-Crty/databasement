<?php

namespace App\Livewire\Dashboard;

use App\Models\Snapshot;
use App\Support\Formatters;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
class StorageCard extends Component
{
    public string $totalStorage = '0 B';

    public function mount(): void
    {
        $totalBytes = Snapshot::whereRelation('job', 'status', 'completed')->sum('file_size');
        $this->totalStorage = Formatters::humanFileSize((int) $totalBytes);
    }

    public function placeholder(): View
    {
        return view('components.lazy-placeholder', ['type' => 'stats']);
    }

    public function render(): View
    {
        return view('livewire.dashboard.storage-card');
    }
}
