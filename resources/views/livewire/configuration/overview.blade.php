<div>
    <x-header :title="__('Overview')" separator progress-indicator>
        <x-slot:subtitle>
            {{ __('All database servers across every organization.') }}
        </x-slot:subtitle>
        <x-slot:actions>
            <div class="hidden sm:flex items-center gap-2">
                <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" clearable
                         icon="o-magnifying-glass" class="!input-sm w-48" />
                @if ($search)
                    <x-button icon="o-x-mark" wire:click="clear" spinner class="btn-ghost btn-sm"
                              tooltip="{{ __('Clear search') }}" />
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    @include('livewire.configuration._tabs', ['active' => 'overview'])

    <!-- SEARCH (Mobile) -->
    <div class="sm:hidden mb-4">
        <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" clearable
                 icon="o-magnifying-glass" />
    </div>

    <x-card shadow>
        <x-table :headers="$headers" :rows="$servers" :sort-by="$sortBy" with-pagination class="table-fixed">
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if ($search)
                        {{ __('No database servers found matching your search.') }}
                    @else
                        {{ __('No database servers yet.') }}
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_name', $server)
            <div class="flex items-center gap-2 overflow-hidden">
                <x-icon :name="$server->database_type->icon()" class="w-6 h-6" />
                <div>
                    <div class="table-cell-primary">{{ $server->name }}</div>
                    <div class="text-sm font-mono text-base-content/70 truncate">{{ $server->getConnectionLabel() }}</div>
                </div>
            </div>
            @endscope

            @scope('cell_organization', $server)
            <x-badge :value="$server->organization->name" class="badge-ghost" />
            @endscope

            @scope('cell_status', $server)
            <div class="flex flex-col gap-1">
                <x-job-status-indicator :status="$server->latest_backup_status ?? 'never'" />
                @if ($server->latest_backup_at)
                    <span class="text-xs text-base-content/60">{{ \Carbon\Carbon::parse($server->latest_backup_at)->diffForHumans() }}</span>
                @endif
            </div>
            @endscope

            @scope('cell_actions', $server)
            <div class="flex justify-end">
                <x-floating-dropdown right>
                    <x-slot:trigger>
                        <x-button icon="o-ellipsis-vertical" class="btn-ghost btn-sm" :tooltip-left="__('Actions')" />
                    </x-slot:trigger>

                    <x-menu-item :title="__('View server')" icon="o-server-stack"
                                 wire:click="viewServer('{{ $server->id }}')" spinner />
                    <x-menu-item :title="__('View jobs')" icon="o-archive-box"
                                 wire:click="viewJobs('{{ $server->id }}')" spinner />
                </x-floating-dropdown>
            </div>
            @endscope
        </x-table>
    </x-card>
</div>
