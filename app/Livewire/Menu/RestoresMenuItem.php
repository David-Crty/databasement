<?php

namespace App\Livewire\Menu;

use App\Models\BackupJob;
use Livewire\Component;

class RestoresMenuItem extends Component
{
    public bool $isActive = false;

    public function mount(): void
    {
        $this->isActive = request()->routeIs('restores.*') || request()->routeIs('scheduled-restores.*');
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
        <div>
            <x-menu-sub title="{{ __('Restores') }}" icon="o-arrow-uturn-left" :active="$isActive">
                <x-menu-item
                    title="{{ __('History') }}"
                    icon="o-clock"
                    link="{{ route('restores.index') }}"
                    wire:navigate
                    :badge="$this->activeRestoresCount > 0 ? $this->activeRestoresCount : null"
                    badge-classes="badge-warning badge-soft"
                />
                <x-menu-item
                    title="{{ __('Scheduled') }}"
                    icon="o-calendar"
                    link="{{ route('scheduled-restores.index') }}"
                    wire:navigate
                />
            </x-menu-sub>
        </div>
        HTML;
    }
}
