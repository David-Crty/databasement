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
            @can('viewForm', App\Models\DatabaseServer::class)
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
                    <div class="relative inline-flex">
                        <x-icon :name="$server->database_type->icon()" class="w-6 h-6" />
                        <div class="absolute -top-1 -right-1">
                            <livewire:database-server.connection-status :server="$server" lazy :key="'conn-'.$server->id" />
                        </div>
                    </div>
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
                            @include('livewire.database-server._notification-indicator', ['server' => $server])
                        </div>
                        @if($server->description)
                            <div class="text-sm text-base-content/50">{{ Str::limit($server->description, 50) }}</div>
                        @endif
                    </div>
                </div>
            @endscope

            @scope('cell_database_names', $server)
                @php
                    $sqlitePaths = $server->database_type === \App\Enums\DatabaseType::SQLITE
                        ? ($server->backups->first()?->database_names ?? [])
                        : [];
                @endphp
                @if(count($sqlitePaths) > 0)
                    @if(count($sqlitePaths) === 1)
                        <span class="font-mono text-xs">{{ basename($sqlitePaths[0]) }}</span>
                    @else
                        <span class="font-mono text-xs" title="{{ implode(', ', $sqlitePaths) }}">
                            {{ basename($sqlitePaths[0]) }}
                            <span class="text-base-content/50">+{{ count($sqlitePaths) - 1 }}</span>
                        </span>
                    @endif
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_backup', $server)
                @if(! $server->backups_enabled)
                    <span class="badge badge-warning badge-soft badge-xs gap-1">
                        <x-icon name="o-no-symbol" class="w-3 h-3" />
                        {{ __('Disabled') }}
                    </span>
                @elseif($server->backups->isEmpty())
                    <span class="text-base-content/50">-</span>
                @else
                    <div class="space-y-1">
                        @foreach($server->backups as $backup)
                            <div class="flex flex-col">
                                <div class="flex items-center gap-1.5 text-sm">
                                    <x-volume-type-icon :type="$backup->volume->type" class="w-3.5 h-3.5 text-base-content/70" />
                                    <span class="font-medium">{{ $backup->volume->name }}</span>
                                    <span class="text-base-content/40">·</span>
                                    <span class="text-base-content/70">{{ $backup->backupSchedule->name }}</span>
                                    @if($backup->retention_policy === 'gfs')
                                        <span class="text-xs text-info">(GFS: {{ $backup->gfs_keep_daily ?? 0 }}d/{{ $backup->gfs_keep_weekly ?? 0 }}w/{{ $backup->gfs_keep_monthly ?? 0 }}m)</span>
                                    @elseif($backup->retention_policy === 'forever')
                                        <span class="text-xs text-warning">({{ __('Forever') }})</span>
                                    @elseif($backup->retention_days)
                                        <span class="text-xs text-base-content/50">({{ $backup->retention_days }}d)</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
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
                        @foreach($server->backups as $backup)
                            <x-button
                                icon="o-arrow-down-tray"
                                wire:click="runBackup('{{ $backup->id }}')"
                                wire:key="run-backup-{{ $backup->id }}"
                                spinner
                                tooltip="{{ __('Backup now') }} · {{ $backup->getDisplayLabel() }}"
                                class="btn-ghost btn-sm text-info"
                            />
                        @endforeach
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
                    @can('viewForm', $server)
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
    <x-delete-confirmation-modal
        :title="__('Delete Database Server')"
        :message="__('Are you sure you want to delete this database server? This action cannot be undone.')"
        onConfirm="delete"
        :showKeepFiles="$deleteSnapshotCount > 0"
        :snapshotCount="$deleteSnapshotCount"
    />

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
