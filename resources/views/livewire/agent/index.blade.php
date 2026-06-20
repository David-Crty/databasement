<div>
    <!-- HEADER with search (Desktop) -->
    <x-header :title="__('Agents')" separator progress-indicator>
        <x-slot:actions>
            <div class="hidden sm:flex items-center gap-2">
                <x-input
                    :placeholder="__('Search...')"
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="!input-sm w-48"
                />
                @if($search)
                    <x-button
                        icon="o-x-mark"
                        wire:click="clear"
                        spinner
                        class="btn-ghost btn-sm"
                        :tooltip="__('Clear search')"
                    />
                @endif
            </div>
            @can('create', App\Models\Agent::class)
                <x-button :label="__('Add Agent')" :link="route('agents.create')" icon="o-plus" class="btn-primary btn-sm" wire:navigate />
            @endcan
        </x-slot:actions>
    </x-header>

    <x-alert class="alert-info alert-vertical sm:alert-horizontal rounded-md mb-4" icon="o-information-circle">
        {{ __('Agents run on remote networks to back up database servers that are not directly accessible. They are optional, you only need them if your servers are behind a firewall or private network.') }}
    </x-alert>

    <!-- SEARCH (Mobile) -->
    <div class="sm:hidden mb-4">
        <x-input
            :placeholder="__('Search...')"
            wire:model.live.debounce="search"
            clearable
            icon="o-magnifying-glass"
        />
    </div>

    <!-- TABLE -->
    <x-card shadow>
        <x-table :headers="$headers" :rows="$agents" :sort-by="$sortBy" with-pagination>
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($search)
                        {{ __('No agents found matching your search.') }}
                    @else
                        {{ __('No agents yet.') }}
                        @can('create', App\Models\Agent::class)
                            <a href="{{ route('agents.create') }}" class="link link-primary" wire:navigate>
                                {{ __('Create your first one.') }}
                            </a>
                        @endcan
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_name', $agent)
                <div class="table-cell-primary">{{ $agent->name }}</div>
            @endscope

            @scope('cell_status', $agent)
                @if($agent->isOnline())
                    <span class="badge badge-success badge-sm gap-1 whitespace-nowrap">
                        <span class="w-2 h-2 rounded-full bg-success animate-pulse"></span>
                        {{ __('Online') }}
                    </span>
                @elseif($agent->last_heartbeat_at)
                    <span class="badge badge-warning badge-sm gap-1 whitespace-nowrap">
                        <span class="w-2 h-2 rounded-full bg-warning"></span>
                        {{ __('Offline') }}
                    </span>
                @else
                    <span class="badge badge-ghost badge-sm whitespace-nowrap">{{ __('Never connected') }}</span>
                @endif
            @endscope

            @scope('cell_servers', $agent)
                <span class="badge badge-ghost badge-sm">{{ $agent->database_servers_count }}</span>
            @endscope

            @scope('cell_last_heartbeat_at', $agent)
                @if($agent->last_heartbeat_at)
                    <div class="table-cell-primary">{{ \App\Support\Formatters::humanDate($agent->last_heartbeat_at) }}</div>
                    <div class="text-sm text-base-content/70">{{ $agent->last_heartbeat_at->diffForHumans() }}</div>
                @else
                    <span class="text-base-content/50">{{ __('Never') }}</span>
                @endif
            @endscope

            @scope('cell_created_at', $agent)
                <div class="table-cell-primary">{{ \App\Support\Formatters::humanDate($agent->created_at) }}</div>
                <div class="text-sm text-base-content/70">{{ $agent->created_at->diffForHumans() }}</div>
            @endscope

            @scope('actions', $agent)
                <div class="flex gap-2 justify-end">
                    @can('update', $agent)
                        <x-button
                            icon="o-pencil"
                            :link="route('agents.edit', $agent)"
                            wire:navigate
                            :tooltip="__('Edit')"
                            class="btn-ghost btn-sm"
                        />
                    @endcan
                    @can('delete', $agent)
                        <x-button
                            icon="o-trash"
                            wire:click="confirmDelete('{{ $agent->id }}')"
                            :tooltip="__('Delete')"
                            class="btn-ghost btn-sm text-error"
                        />
                    @endcan
                </div>
            @endscope
        </x-table>
    </x-card>

    <!-- DELETE CONFIRMATION MODAL -->
    <x-modal wire:model="showDeleteModal" :title="__('Delete Agent')" class="backdrop-blur">
        <p>{{ __('Are you sure you want to delete this agent? This action cannot be undone.') }}</p>

        @if($deleteServerCount > 0)
            <x-alert icon="o-exclamation-triangle" class="alert-warning mt-4">
                {{ trans_choice(':count database server is assigned to this agent and will be unlinked.|:count database servers are assigned to this agent and will be unlinked.', $deleteServerCount, ['count' => $deleteServerCount]) }}
            </x-alert>
        @endif

        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showDeleteModal = false" />
            <x-button :label="__('Delete')" class="btn-error" wire:click="delete" />
        </x-slot:actions>
    </x-modal>
</div>
