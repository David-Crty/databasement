<div>
    <!-- HEADER with search (Desktop) -->
    <x-header title="{{ __('Database Servers') }}" separator progress-indicator>
        <x-slot:actions>
            <div class="hidden sm:flex items-center gap-2">
                <x-input
                    placeholder="{{ __('Search...') }}"
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
                        tooltip="{{ __('Clear search') }}"
                    />
                @endif
            </div>
            @can('create', App\Models\DatabaseServer::class)
                <x-button label="{{ __('Add Server') }}" link="{{ route('database-servers.create') }}" icon="o-plus" class="btn-primary btn-sm" wire:navigate />
            @endcan
        </x-slot:actions>
    </x-header>

    <!-- SEARCH (Mobile) -->
    <div class="sm:hidden mb-4">
        <x-input
            placeholder="{{ __('Search...') }}"
            wire:model.live.debounce="search"
            clearable
            icon="o-magnifying-glass"
        />
    </div>

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
                        <div class="flex items-center gap-2 text-sm text-base-content/70">
                            <x-popover>
                                <x-slot:trigger>
                                    <div class="flex items-center gap-1 cursor-pointer">
                                        @if($server->database_type->value === 'sqlite')
                                            <x-icon name="o-document" class="w-3 h-3" />
                                        @endif
                                        <span class="font-mono truncate max-w-48">{{ $server->getConnectionLabel() }}</span>
                                    </div>
                                </x-slot:trigger>
                                <x-slot:content class="text-sm font-mono">
                                    {{ $server->getConnectionDetails() }}
                                </x-slot:content>
                            </x-popover>
                            @if($server->getSshDisplayName())
                                <x-popover>
                                    <x-slot:trigger>
                                        <x-badge value="SSH" class="badge-warning badge-soft badge-xs cursor-pointer" />
                                    </x-slot:trigger>
                                    <x-slot:content class="text-sm">
                                        {{ __('Via') }} {{ $server->getSshDisplayName() }}
                                    </x-slot:content>
                                </x-popover>
                            @endif
                        </div>
                        @if($server->description)
                            <div class="text-sm text-base-content/50">{{ Str::limit($server->description, 50) }}</div>
                        @endif
                    </div>
                </div>
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
                    <div class="table-cell-primary flex items-center gap-1.5">
                        <x-volume-type-icon :type="$server->backup->volume->type" class="w-4 h-4 text-base-content/70" />
                        {{ $server->backup->volume->name }}
                    </div>
                    <div class="text-sm text-base-content/70">
                        {{ $server->backup->backupSchedule->name }}
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

    <!-- REDIS RESTORE INFO MODAL -->
    <x-modal wire:model="showRedisRestoreModal" :title="__('Restore Redis / Valkey Snapshot')" class="backdrop-blur">
        <div class="space-y-4">
            <x-alert class="alert-info" icon="o-information-circle">
                <div>
                    <span class="font-bold">{{ __('Manual Restore Required') }}</span>
                    <p class="text-sm mt-1">{{ __('Automated restore is not supported for Redis/Valkey. RDB snapshots must be restored manually.') }}</p>
                </div>
            </x-alert>

            <div class="p-4 border rounded-lg bg-base-200 border-base-300 space-y-3">
                <div class="text-sm font-semibold">{{ __('How to Restore an RDB Snapshot') }}</div>
                <ol class="list-decimal list-inside text-sm space-y-2 opacity-80">
                    <li>{{ __('Download the snapshot archive (.rdb.gz) from your storage volume.') }}</li>
                    <li>{{ __('Extract the RDB file from the archive (e.g., gunzip snapshot.rdb.gz).') }}</li>
                    <li>{{ __('Stop the Redis/Valkey server.') }}</li>
                    <li>{{ __('Copy the RDB file to the Redis data directory, replacing dump.rdb.') }}</li>
                    <li>{{ __('Set correct file permissions (e.g., chown redis:redis dump.rdb).') }}</li>
                    <li>{{ __('Restart the Redis/Valkey server.') }}</li>
                </ol>
            </div>

            @if($restoreId)
                <a href="{{ route('jobs.index', ['serverFilter' => $restoreId, 'typeFilter' => 'backup']) }}"
                   class="btn btn-sm btn-outline gap-2" wire:navigate>
                    <x-icon name="o-arrow-down-tray" class="w-4 h-4" />
                    {{ __('View Backup Snapshots') }}
                </a>
            @endif
        </div>

        <x-slot:actions>
            <x-button label="{{ __('Close') }}" @click="$wire.showRedisRestoreModal = false" />
        </x-slot:actions>
    </x-modal>
</div>
