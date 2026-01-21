<div>
    <!-- HEADER -->
    <x-header title="{{ __('Database Servers') }}" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" clearable icon="o-magnifying-glass" />
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="{{ __('Filters') }}" @click="$wire.drawer = true" responsive icon="o-funnel" class="btn-ghost" />
            @can('create', App\Models\DatabaseServer::class)
                <x-button label="{{ __('Add Server') }}" link="{{ route('database-servers.create') }}" icon="o-plus" class="btn-primary" wire:navigate />
            @endcan
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
                <div class="flex items-center gap-2">
                    <x-database-type-icon :type="$server->database_type" />
                    <div>
                        <div class="table-cell-primary">{{ $server->name }}</div>
                        @if($server->description)
                            <div class="text-sm text-base-content/70">{{ Str::limit($server->description, 50) }}</div>
                        @endif
                    </div>
                </div>
            @endscope

            @scope('cell_host', $server)
                @if($server->database_type === 'sqlite')
                    <x-popover>
                        <x-slot:trigger>
                            <div class="flex items-center gap-1 cursor-pointer">
                                <x-icon name="o-document" class="w-4 h-4 text-base-content/50" />
                                <span class="font-mono text-sm truncate max-w-48">{{ basename($server->sqlite_path) }}</span>
                            </div>
                        </x-slot:trigger>
                        <x-slot:content class="text-sm font-mono">
                            {{ $server->sqlite_path }}
                        </x-slot:content>
                    </x-popover>
                @else
                    <x-popover>
                        <x-slot:trigger>
                            <span class="font-mono text-sm truncate max-w-48 block cursor-pointer">{{ $server->host }}:{{ $server->port }}</span>
                        </x-slot:trigger>
                        <x-slot:content class="text-sm font-mono">
                            {{ $server->host }}:{{ $server->port }}
                        </x-slot:content>
                    </x-popover>
                @endif
            @endscope

            @scope('cell_database_names', $server)
                @if($server->database_type === 'sqlite')
                    <span class="text-base-content/50 italic">{{ __('Single file') }}</span>
                @elseif($server->backup_all_databases)
                    <x-badge value="{{ __('All') }}" class="badge-info badge-soft" />
                @elseif($server->database_names && count($server->database_names) > 0)
                    @if(count($server->database_names) === 1)
                        {{ $server->database_names[0] }}
                    @else
                        <span title="{{ implode(', ', $server->database_names) }}">
                            {{ $server->database_names[0] }}
                            <span class="text-base-content/50">+{{ count($server->database_names) - 1 }}</span>
                        </span>
                    @endif
                @else
                    -
                @endif
            @endscope

            @scope('cell_backup', $server)
                @if($server->backup)
                    <div class="table-cell-primary">{{ $server->backup->volume->name }}</div>
                    <div class="text-sm text-base-content/70 capitalize">
                        {{ $server->backup->recurrence }}
                        @if($server->backup->retention_policy === 'gfs')
                            <span class="text-info">(GFS: {{ $server->backup->gfs_keep_daily ?? 0 }}d/{{ $server->backup->gfs_keep_weekly ?? 0 }}w/{{ $server->backup->gfs_keep_monthly ?? 0 }}m)</span>
                        @elseif($server->backup->retention_policy === 'forever')
                            <span class="text-warning">({{ __('Forever') }})</span>
                        @elseif($server->backup->retention_days)
                            <span>({{ $server->backup->retention_days }}d)</span>
                        @endif
                    </div>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_jobs', $server)
                <div class="flex items-center gap-3 text-sm">
                    @if($server->snapshots_count > 0)
                        <a href="{{ route('jobs.index', ['serverFilter' => $server->id, 'typeFilter' => 'backup']) }}"
                           class="flex items-center gap-1 hover:text-info transition-colors tooltip"
                           data-tip="{{ __('View backup jobs') }}"
                           wire:navigate>
                            <x-icon name="o-arrow-down-tray" class="w-4 h-4" />
                            <span>{{ $server->snapshots_count }}</span>
                        </a>
                    @else
                        <span class="flex items-center gap-1 text-base-content/30">
                            <x-icon name="o-arrow-down-tray" class="w-4 h-4" />
                            <span>0</span>
                        </span>
                    @endif

                    @if($server->restores_count > 0)
                        <a href="{{ route('jobs.index', ['serverFilter' => $server->id, 'typeFilter' => 'restore']) }}"
                           class="flex items-center gap-1 hover:text-success transition-colors tooltip"
                           data-tip="{{ __('View restore jobs') }}"
                           wire:navigate>
                            <x-icon name="o-arrow-up-tray" class="w-4 h-4" />
                            <span>{{ $server->restores_count }}</span>
                        </a>
                    @else
                        <span class="flex items-center gap-1 text-base-content/30">
                            <x-icon name="o-arrow-up-tray" class="w-4 h-4" />
                            <span>0</span>
                        </span>
                    @endif
                </div>
            @endscope

            @scope('actions', $server)
                <div class="flex gap-2 justify-end">
                    @can('backup', $server)
                        @if($server->backup)
                            <x-button
                                icon="o-arrow-down-tray"
                                wire:click="runBackup('{{ $server->id }}')"
                                spinner
                                tooltip="{{ __('Backup Now') }}"
                                class="btn-ghost btn-sm text-info"
                            />
                        @endif
                    @endcan
                    @can('restore', $server)
                        <x-button
                            icon="o-arrow-up-tray"
                            wire:click="confirmRestore('{{ $server->id }}')"
                            spinner
                            tooltip="{{ __('Restore') }}"
                            class="btn-ghost btn-sm text-success"
                        />
                    @endcan
                    @can('update', $server)
                        <x-button
                            icon="o-pencil"
                            link="{{ route('database-servers.edit', $server) }}"
                            wire:navigate
                            tooltip="{{ __('Edit') }}"
                            class="btn-ghost btn-sm"
                        />
                    @endcan
                    @can('delete', $server)
                        <x-button
                            icon="o-trash"
                            wire:click="confirmDelete('{{ $server->id }}')"
                            tooltip="{{ __('Delete') }}"
                            class="btn-ghost btn-sm text-error"
                        />
                    @endcan
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
    <x-modal wire:model="showDeleteModal" :title="__('Delete Database Server')" class="backdrop-blur">
        <p>{{ __('Are you sure you want to delete this database server? This action cannot be undone.') }}</p>

        @if($deleteSnapshotCount > 0)
            <x-alert icon="o-exclamation-triangle" class="alert-warning mt-4">
                {{ trans_choice(':count snapshot will also be deleted.|:count snapshots will also be deleted.', $deleteSnapshotCount, ['count' => $deleteSnapshotCount]) }}
            </x-alert>
        @endif

        <x-slot:actions>
            <x-button label="{{ __('Cancel') }}" @click="$wire.showDeleteModal = false" />
            <x-button label="{{ __('Delete') }}" class="btn-error" wire:click="delete" />
        </x-slot:actions>
    </x-modal>

    <!-- RESTORE MODAL -->
    <livewire:database-server.restore-modal />
</div>
