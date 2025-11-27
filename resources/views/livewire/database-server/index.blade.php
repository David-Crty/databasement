<div>
    <!-- HEADER -->
    <x-header title="{{ __('Database Servers') }}" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" clearable icon="o-magnifying-glass" />
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="{{ __('Filters') }}" @click="$wire.drawer = true" responsive icon="o-funnel" class="btn-ghost" />
            <x-button label="{{ __('Add Server') }}" link="{{ route('database-servers.create') }}" icon="o-plus" class="btn-primary" wire:navigate />
        </x-slot:actions>
    </x-header>

    <!-- TABLE -->
    <x-card shadow>
        <x-table :headers="$headers" :rows="$servers" :sort-by="$sortBy" with-pagination>
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($search)
                        {{ __('No database servers found matching your search.') }}
                    @else
                        {{ __('No database servers yet.') }}
                        <a href="{{ route('database-servers.create') }}" class="link link-primary" wire:navigate>
                            {{ __('Create your first one.') }}
                        </a>
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_name', $server)
                <div class="table-cell-primary">{{ $server->name }}</div>
                @if($server->description)
                    <div class="text-sm text-base-content/70">{{ Str::limit($server->description, 50) }}</div>
                @endif
            @endscope

            @scope('cell_database_type', $server)
                <x-badge :value="$server->database_type" />
            @endscope

            @scope('cell_host', $server)
                {{ $server->host }}:{{ $server->port }}
            @endscope

            @scope('cell_database_name', $server)
                {{ $server->database_name ?? '-' }}
            @endscope

            @scope('cell_backup', $server)
                @if($server->backup)
                    <div class="table-cell-primary">{{ $server->backup->volume->name }}</div>
                    <div class="text-sm text-base-content/70 capitalize">{{ $server->backup->recurrence }}</div>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_created_at', $server)
                {{ $server->created_at->diffForHumans() }}
            @endscope

            @scope('actions', $server)
                <div class="flex gap-2 justify-end">
                    @if($server->backup)
                        <x-button
                            icon="o-arrow-down-tray"
                            wire:click="runBackup('{{ $server->id }}')"
                            spinner
                            tooltip="{{ __('Backup Now') }}"
                            class="btn-ghost btn-sm text-info"
                        />
                    @endif
                    <x-button
                        icon="o-arrow-up-tray"
                        wire:click="confirmRestore('{{ $server->id }}')"
                        spinner
                        tooltip="{{ __('Restore') }}"
                        class="btn-ghost btn-sm text-success"
                    />
                    <x-button
                        icon="o-pencil"
                        link="{{ route('database-servers.edit', $server) }}"
                        wire:navigate
                        tooltip="{{ __('Edit') }}"
                        class="btn-ghost btn-sm"
                    />
                    <x-button
                        icon="o-trash"
                        wire:click="confirmDelete('{{ $server->id }}')"
                        tooltip="{{ __('Delete') }}"
                        class="btn-ghost btn-sm text-error"
                    />
                </div>
            @endscope
        </x-table>
    </x-card>

    <!-- FILTER DRAWER -->
    <x-drawer wire:model="drawer" title="{{ __('Filters') }}" right separator with-close-button class="lg:w-1/3">
        <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" icon="o-magnifying-glass" @keydown.enter="$wire.drawer = false" />

        <x-slot:actions>
            <x-button label="{{ __('Reset') }}" icon="o-x-mark" wire:click="clear" spinner />
            <x-button label="{{ __('Done') }}" icon="o-check" class="btn-primary" @click="$wire.drawer = false" />
        </x-slot:actions>
    </x-drawer>

    <!-- DELETE CONFIRMATION MODAL -->
    <x-delete-confirmation-modal
        :title="__('Delete Database Server')"
        :message="__('Are you sure you want to delete this database server? This action cannot be undone.')"
        onConfirm="delete"
    />

    <!-- RESTORE MODAL -->
    <livewire:database-server.restore-modal />
</div>
