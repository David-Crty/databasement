<div>
    <x-index-header :title="__('Database Servers')" :search="$search">
        @can('viewForm', App\Models\DatabaseServer::class)
            <x-button :label="__('Add Server')" :link="route('database-servers.create')" icon="o-plus" class="btn-primary btn-sm" wire:navigate />
        @endcan
    </x-index-header>

    <!-- TABLE -->
    <x-card shadow>
        <x-table :headers="$headers" :rows="$servers" :sort-by="$sortBy" with-pagination class="table-fixed">
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if ($search)
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
            <div class="flex items-center gap-2 overflow-hidden">
                <div class="relative inline-flex">
                    <x-icon :name="$server->database_type->icon()" class="w-6 h-6" />
                    <div class="absolute -top-1 -right-1">
                        <livewire:database-server.connection-status :server="$server" lazy :key="'conn-' . $server->id" />
                    </div>
                </div>
                <div>
                    @can('view', $server)
                        <a href="{{ route('database-servers.show', $server) }}" wire:navigate
                           class="table-cell-primary link link-hover hover:text-primary">{{ $server->name }}</a>
                    @else
                        <div class="table-cell-primary">{{ $server->name }}</div>
                    @endcan
                    <div class="flex items-center gap-2 text-sm text-base-content/70">
                        @include('livewire.database-server._notification-indicator', [
                            'server' => $server,
                        ])
                        <x-popover>
                            <x-slot:trigger>
                                <div class="flex items-center gap-1 cursor-pointer">
                                    @if ($server->database_type->value === 'sqlite')
                                        <x-icon name="o-document" class="w-3 h-3" />
                                    @endif
                                    <span class="font-mono block truncate">{{ $server->getConnectionLabel() }}</span>
                                </div>
                            </x-slot:trigger>
                            <x-slot:content class="text-sm font-mono">
                                {{ $server->getConnectionDetails() }}
                            </x-slot:content>
                        </x-popover>
                        @if ($server->getSshDisplayName())
                            <x-popover>
                                <x-slot:trigger>
                                    <x-badge value="SSH" class="badge-warning badge-xs cursor-pointer" />
                                </x-slot:trigger>
                                <x-slot:content class="text-sm">
                                    {{ __('Via') }} {{ $server->getSshDisplayName() }}
                                </x-slot:content>
                            </x-popover>
                        @endif
                    </div>
                    @if ($server->description)
                        <div class="text-sm text-base-content/50">{{ Str::limit($server->description, 50) }}</div>
                    @endif
                </div>
            </div>
            @endscope

            @scope('cell_backup', $server)
            @if (!$server->backups_enabled)
                <span class="badge badge-warning badge-xs gap-1">
                        <x-icon name="o-no-symbol" class="w-3 h-3" />
                        {{ __('Disabled') }}
                    </span>
            @elseif($server->backups->isEmpty())
                <span class="text-base-content/50">—</span>
            @else
                <div class="flex flex-col gap-1 min-w-0 w-full">
                    @foreach ($server->backups as $backup)
                        <x-databaserver-backup-index :backup="$backup" :server="$server" />
                    @endforeach
                </div>
            @endif
            @endscope

            @scope('cell_jobs', $server)
            <div class="flex flex-col items-center justify-center text-sm leading-tight text-center">
                <a href="{{ route('snapshots.index', ['serverFilter' => $server->id]) }}"
                   class="flex items-center gap-1 hover:text-info transition-colors tooltip @if ($server->snapshots_count === 0) pointer-events-none opacity-50 cursor-not-allowed @endif"
                   data-tip="{{ __('View snapshots') }}" wire:navigate>
                    <x-icon name="o-archive-box" class="w-4 h-4" />
                    <span>{{ $server->snapshots_count }}</span>
                </a>

                <a href="{{ route('restores.index', ['targetServerFilter' => $server->id]) }}"
                   class="flex items-center gap-1 hover:text-success transition-colors tooltip @if ($server->restores_count === 0) pointer-events-none opacity-50 cursor-not-allowed @endif"
                   data-tip="{{ __('View restores') }}" wire:navigate>
                    <x-icon name="o-arrow-uturn-left" class="w-4 h-4" />
                    <span>{{ $server->restores_count }}</span>
                </a>
            </div>
            @endscope

            @scope('cell_actions', $server, $canAdminer)
            <div class="flex justify-end">
                <x-floating-dropdown right>
                    <x-slot:trigger>
                        <x-button icon="o-ellipsis-vertical" class="btn-ghost btn-sm" :tooltip-left="__('Actions')" />
                    </x-slot:trigger>

                    @can('view', $server)
                        <x-menu-item :title="__('View')" icon="o-eye"
                                     link="{{ route('database-servers.show', $server) }}" wire:navigate />
                    @endcan
                    @if($canAdminer && $server->supportsAdminer())
                        <x-menu-item :title="__('Browse')" icon="o-table-cells"
                                     wire:click="openAdminer('{{ $server->id }}')" spinner
                                     class="text-accent" />
                    @endif
                    @can('backup', $server)
                        <x-menu-item :title="__('Backup now')" icon="bi.database-fill-up"
                                     wire:click="runBackupAll('{{ $server->id }}')" spinner
                                     class="text-info" />
                    @endcan
                    @can('restore', $server)
                        <x-menu-item :title="__('Restore')" icon="bi.database-fill-down"
                                     wire:click="confirmRestore('{{ $server->id }}')" spinner
                                     class="text-success" />
                    @endcan
                    @can('update', $server)
                        @if($server->backups->isNotEmpty())
                            <x-menu-item
                                :title="$server->backups_enabled ? __('Disable Backup') : __('Enable Backup')"
                                :icon="$server->backups_enabled ? 'o-pause-circle' : 'o-play-circle'"
                                wire:click="toggleBackupsEnabled('{{ $server->id }}')"
                                spinner
                            />
                        @endif
                    @endcan
                    @can('viewForm', $server)
                        <x-menu-item :title="__('Edit')" icon="o-pencil"
                                     link="{{ route('database-servers.edit', $server) }}" wire:navigate />
                    @endcan
                    @can('delete', $server)
                        <x-menu-separator />
                        <x-menu-item :title="__('Delete')" icon="o-trash"
                                     wire:click="confirmDelete('{{ $server->id }}')"
                                     spinner
                                     class="text-error" />
                    @endcan
                </x-floating-dropdown>
            </div>
            @endscope
        </x-table>
    </x-card>

    <!-- DELETE CONFIRMATION MODAL -->
    <x-delete-confirmation-modal :title="__('Delete Database Server')" :message="__('Are you sure you want to delete this database server? This action cannot be undone.')" onConfirm="delete" :showKeepFiles="$deleteSnapshotCount > 0"
                                 :snapshotCount="$deleteSnapshotCount" />

    <!-- RESTORE MODAL -->
    <livewire:restore.modal />

    <!-- ADMINER MODAL -->
    <livewire:database-server.adminer-modal />

    <!-- REDIS RESTORE INFO MODAL -->
    @include('partials.redis-restore-modal', ['serverId' => $restoreId])

</div>
