<?php

namespace App\Livewire\Menu;

use App\Models\BackupJob;
use Livewire\Component;

class SnapshotsMenuItem extends Component
{
    public bool $isActive = false;

    public function mount(): void
    {
        $this->isActive = request()->routeIs('snapshots.*');
    }

    public function getActiveSnapshotsCountProperty(): int
    {
        return BackupJob::forCurrentOrg()
            ->whereIn('status', ['running', 'pending'])
            ->whereHas('snapshot')
            ->count();
    }

    public function render(): string
    {
        return <<<'HTML'
        <div wire:poll.5s>
            <x-menu-item
                title="{{ __('Snapshots') }}"
                icon="o-archive-box"
                link="{{ route('snapshots.index') }}"
                wire:navigate
                :active="$isActive"
                :badge="$this->activeSnapshotsCount > 0 ? $this->activeSnapshotsCount : null"
                badge-classes="badge-warning badge-soft"
            />
        </div>
        HTML;
    }
}
