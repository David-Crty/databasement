<?php

namespace App\Livewire\Menu;

use App\Models\BackupJob;
use Livewire\Component;

class RestoresMenuItem extends Component
{
    public bool $isActive = false;

    public function mount(): void
    {
        $this->isActive = request()->routeIs('restores.*');
    }

    public function getActiveRestoresCountProperty(): int
    {
        return BackupJob::forCurrentOrg()
            ->whereIn('status', ['running', 'pending'])
            ->whereHas('restore')
            ->count();
    }

    public function render(): string
    {
        return <<<'HTML'
        <div wire:poll.5s>
            <x-menu-item
                title="{{ __('Restores') }}"
                icon="o-arrow-uturn-left"
                link="{{ route('restores.index') }}"
                wire:navigate
                :active="$isActive"
                :badge="$this->activeRestoresCount > 0 ? $this->activeRestoresCount : null"
                badge-classes="badge-warning badge-soft"
            />
        </div>
        HTML;
    }
}
