<div>
    @php
        use App\Enums\DatabaseSelectionMode;
        use App\Enums\DatabaseType;
        use App\Enums\NotificationChannelSelection;
        use App\Enums\NotificationTrigger;
        use App\Livewire\Forms\BackupForm;

        $sshConfig = $server->sshConfig;
        $agent = $server->agent;
        $isSqlite = $server->database_type === DatabaseType::SQLITE;
        $isPostgres = $server->database_type === DatabaseType::POSTGRESQL;
        $sslEnabled = (bool) $server->getExtraConfig('ssl_enabled', false);
        $authSource = $server->getExtraConfig('auth_source');
        $dumpFlags = $server->getExtraConfig('dump_flags');
        $dumpFormat = $isPostgres ? ($server->getExtraConfig('dump_format', 'plain')) : null;
        $showDumpCard = $dumpFlags || ($isPostgres && $dumpFormat === 'custom');

        $trigger = $server->notification_trigger;
        $selection = $server->notification_channel_selection;
        $channelCount = $activeChannels->count();
        $notifDisabled = $trigger === NotificationTrigger::None;
        $notifNoChannels = ! $notifDisabled && $channelCount === 0;

        $triggerMeta = [
            'all' => ['icon' => 'o-bell-alert', 'figure' => 'text-success', 'status' => 'status-success'],
            'success' => ['icon' => 'o-check-circle', 'figure' => 'text-info', 'status' => 'status-info'],
            'failure' => ['icon' => 'o-exclamation-triangle', 'figure' => 'text-warning', 'status' => 'status-warning'],
            'none' => ['icon' => 'o-bell-slash', 'figure' => 'text-base-content/40', 'status' => ''],
        ][$trigger->value];
    @endphp

    {{-- ── Hero header ── --}}
    <div class="card card-border bg-base-100 shadow-sm mb-6">
        <div class="card-body p-5 sm:p-6">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-4 min-w-0">
                    <div class="relative shrink-0">
                        <div class="avatar avatar-placeholder">
                            <div class="bg-base-200 rounded-box w-14 h-14">
                                <x-icon :name="$server->database_type->icon()" class="w-9 h-9 text-base-content" />
                            </div>
                        </div>
                        <div class="absolute -top-1 -right-1">
                            <livewire:database-server.connection-status :server="$server" lazy :key="'conn-show-' . $server->id" />
                        </div>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h1 class="text-xl font-semibold leading-tight">{{ $server->name }}</h1>
                        @if($server->description)
                            <p class="mt-1 line-clamp-2 text-sm opacity-60 leading-relaxed">{{ $server->description }}</p>
                        @endif

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="badge badge-ghost gap-1.5 whitespace-nowrap">
                                <x-icon name="o-circle-stack" class="w-3.5 h-3.5" />
                                {{ $server->database_type->label() }}
                            </span>

                            @if($sshConfig)
                                <span class="badge badge-warning badge-soft gap-1.5 whitespace-nowrap max-w-full">
                                    <x-icon name="o-shield-check" class="w-3.5 h-3.5 shrink-0" />
                                    <span class="truncate">
                                        {{ __('SSH tunnel') }}
                                        <code class="font-mono">{{ $sshConfig->username . '@' . $sshConfig->host . ':' . $sshConfig->port }}</code>
                                    </span>
                                </span>
                            @endif

                            @if($agent)
                                @php $online = $agent->isOnline(); @endphp
                                <span class="badge {{ $online ? 'badge-success' : 'badge-error' }} badge-soft gap-1.5 whitespace-nowrap max-w-full">
                                    <x-icon :name="$online ? 'o-signal' : 'o-signal-slash'" class="w-3.5 h-3.5 shrink-0" />
                                    <span class="truncate">{{ __('Agent') }}: {{ $agent->name }}</span>
                                    <span class="status {{ $online ? 'status-success animate-pulse' : 'status-error' }} shrink-0"></span>
                                </span>
                            @endif

                            @if($server->backups_enabled)
                                <span class="badge badge-success badge-soft gap-1.5 whitespace-nowrap">
                                    <x-icon name="o-check-circle" class="w-3.5 h-3.5" />
                                    {{ trans_choice('{0} Backups enabled (no config)|{1} Backups enabled (:count config)|[2,*] Backups enabled (:count configs)', $server->backups->count(), ['count' => $server->backups->count()]) }}
                                </span>
                            @else
                                <span class="badge badge-warning badge-soft gap-1.5 whitespace-nowrap">
                                    <x-icon name="o-no-symbol" class="w-3.5 h-3.5" />
                                    {{ __('Backups disabled') }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <x-button :label="__('Back')" icon="o-arrow-left" link="{{ route('database-servers.index') }}"
                              class="btn-ghost btn-sm" wire:navigate />
                    @can('backup', $server)
                        <x-button :label="__('Backup now')" icon="bi.database-fill-up" wire:click="runBackupAll" spinner
                                  class="btn-outline btn-info btn-sm" />
                    @endcan
                    @can('restore', $server)
                        <x-button :label="__('Restore')" icon="bi.database-fill-down" wire:click="confirmRestore" spinner
                                  class="btn-outline btn-success btn-sm" />
                    @endcan
                    @can('viewForm', $server)
                        <x-button :label="__('Edit')" icon="o-pencil" link="{{ route('database-servers.edit', $server) }}"
                                  class="btn-primary btn-sm" wire:navigate />
                    @endcan
                    @can('delete', $server)
                        <x-button icon="o-trash" wire:click="confirmDelete" tooltip-left="{{ __('Delete') }}"
                                  class="btn-ghost btn-sm text-error" />
                    @endcan
                </div>
            </div>
        </div>
    </div>

    {{-- ── Stat strip ── --}}
    <div class="stats stats-vertical lg:stats-horizontal bg-base-100 shadow-sm border border-base-200 w-full mb-6">
        <a href="{{ route('snapshots.index', ['serverFilter' => $server->id]) }}" wire:navigate
           class="stat group cursor-pointer transition-colors hover:bg-base-200">
            <div class="stat-figure text-info transition-transform group-hover:scale-110">
                <x-icon name="o-archive-box" class="w-8 h-8" />
            </div>
            <div class="stat-title group-hover:text-base-content">{{ __('Snapshots') }}</div>
            <div class="stat-value font-mono transition-colors group-hover:text-info">{{ $snapshotsCount }}</div>
            <div class="stat-desc inline-flex items-center gap-1 group-hover:text-info">
                {{ __('Click to view all') }}
                <x-icon name="o-arrow-right" class="w-3 h-3 transition-transform group-hover:translate-x-0.5" />
            </div>
        </a>

        <a href="{{ route('restores.index', ['targetServerFilter' => $server->id]) }}" wire:navigate
           class="stat group cursor-pointer transition-colors hover:bg-base-200">
            <div class="stat-figure text-warning transition-transform group-hover:scale-110">
                <x-icon name="o-arrow-uturn-left" class="w-8 h-8" />
            </div>
            <div class="stat-title group-hover:text-base-content">{{ __('Restores') }}</div>
            <div class="stat-value font-mono transition-colors group-hover:text-warning">{{ $restoresCount }}</div>
            <div class="stat-desc inline-flex items-center gap-1 group-hover:text-warning">
                {{ __('Click to view all') }}
                <x-icon name="o-arrow-right" class="w-3 h-3 transition-transform group-hover:translate-x-0.5" />
            </div>
        </a>

        <div class="stat">
            <div class="stat-figure {{ $triggerMeta['figure'] }}">
                <x-icon :name="$triggerMeta['icon']" class="w-8 h-8" />
            </div>
            <div class="stat-title">{{ __('Notifications') }}</div>
            <div class="stat-value text-lg! flex items-center gap-2">
                @if($triggerMeta['status'])
                    <span class="status {{ $triggerMeta['status'] }}"></span>
                @endif
                {{ $trigger->label() }}
            </div>
            <div class="stat-desc mt-1">
                @if($notifDisabled)
                    <span class="opacity-60">{{ __('No alerts') }}</span>
                @elseif($notifNoChannels)
                    <span class="text-warning inline-flex items-center gap-1">
                        <x-icon name="o-exclamation-triangle" class="w-3.5 h-3.5" />
                        {{ $selection === NotificationChannelSelection::All
                            ? __('No channels configured')
                            : __('No channels selected') }}
                    </span>
                @else
                    <div class="flex flex-wrap gap-1">
                        @foreach($activeChannels as $channel)
                            <span class="badge badge-ghost badge-sm gap-1" title="{{ $channel->type->label() }}">
                                <x-icon :name="$channel->type->icon()" class="w-3 h-3" />
                                <span class="normal-case">{{ $channel->name }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Two-column body ── --}}
    <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
        {{-- Main: Backup Configurations --}}
        <div class="min-w-0 flex-1 lg:basis-2/3">
            <div class="card card-border bg-base-100 shadow-sm overflow-hidden">
                <div class="flex items-center justify-between border-b border-base-200 px-4 py-3">
                    <div class="flex items-center gap-2.5">
                        <x-icon name="o-server-stack" class="w-4 h-4 opacity-60" />
                        <h2 class="text-sm font-semibold">{{ __('Backup Configurations') }}</h2>
                        <span class="badge badge-ghost badge-sm">{{ $server->backups->count() }}</span>
                    </div>
                    @if(! $server->backups_enabled)
                        <span class="badge badge-warning badge-soft badge-sm">{{ __('Disabled') }}</span>
                    @endif
                </div>

                <div class="p-4">
                    @if($server->backups->isEmpty())
                        <div class="flex flex-col items-center gap-3 py-10 text-center">
                            <div class="avatar avatar-placeholder">
                                <div class="bg-base-200 rounded-full w-12 h-12">
                                    <x-icon name="o-server-stack" class="w-6 h-6 opacity-50" />
                                </div>
                            </div>
                            <div>
                                <p class="text-sm font-medium">{{ __('No backup configurations') }}</p>
                                <p class="mt-1 text-xs opacity-60">
                                    {{ __('Add a backup configuration to start protecting this server.') }}
                                </p>
                            </div>
                            @can('viewForm', $server)
                                <x-button :label="__('Add a backup configuration')" icon="o-plus"
                                          link="{{ route('database-servers.edit', $server) }}"
                                          class="btn-primary btn-sm" wire:navigate />
                            @endcan
                        </div>
                    @else
                        <div class="flex flex-col gap-3">
                            @foreach($server->backups as $backup)
                                @php
                                    $entry = $backup->toArray();
                                    $summaryWhat = BackupForm::selectionSummary($entry, $server->database_type);
                                    $summaryWhere = $backup->volume?->name;
                                    $summaryWhen = $backup->backupSchedule
                                        ? \App\Support\Formatters::cronTranslation($backup->backupSchedule->expression).' ('.$backup->backupSchedule->name.')'
                                        : null;
                                    $summaryKeep = BackupForm::retentionSummary($entry);

                                    $mode = $backup->database_selection_mode;
                                    $isSqliteServer = $server->database_type === DatabaseType::SQLITE;
                                    $databaseNames = is_array($backup->database_names) ? array_values(array_filter($backup->database_names)) : [];
                                    $showNamesList = ($isSqliteServer && $databaseNames !== [])
                                        || ($mode === DatabaseSelectionMode::Selected && $databaseNames !== []);
                                @endphp
                                <div class="rounded-lg border border-primary/20 bg-primary/5 px-4 py-3.5">
                                    <dl class="grid gap-y-2 gap-x-4 text-sm" style="grid-template-columns: auto 1fr;">
                                        @if($summaryWhat || $showNamesList)
                                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-base-content/50">
                                                <x-icon name="o-circle-stack" class="w-3.5 h-3.5" />
                                                {{ __('What') }}
                                            </dt>
                                            <dd class="font-semibold text-base-content">
                                                @if($showNamesList)
                                                    <div class="flex flex-wrap items-center gap-1.5">
                                                        @foreach($databaseNames as $name)
                                                            <span class="badge badge-ghost badge-sm font-mono normal-case">
                                                                <x-icon :name="$isSqliteServer ? 'o-document' : 'o-circle-stack'" class="w-3 h-3 opacity-60 mr-1" />
                                                                {{ $name }}
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                @elseif($mode === DatabaseSelectionMode::Pattern && ! empty($backup->database_include_pattern))
                                                    <span class="inline-flex items-center gap-1.5">
                                                        {{ __('Databases matching') }}
                                                        <code class="font-mono text-xs px-1.5 py-0.5 rounded bg-base-200">/{{ $backup->database_include_pattern }}/i</code>
                                                    </span>
                                                @else
                                                    {{ $summaryWhat }}
                                                @endif
                                            </dd>
                                        @endif

                                        @if($summaryWhere)
                                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-base-content/50">
                                                <x-icon name="o-server-stack" class="w-3.5 h-3.5" />
                                                {{ __('Where') }}
                                            </dt>
                                            <dd class="font-semibold text-base-content inline-flex items-center gap-1.5">
                                                @if($backup->volume)
                                                    <x-volume-type-icon :type="$backup->volume->type" class="w-3.5 h-3.5 opacity-70" />
                                                @endif
                                                <span>{{ $summaryWhere }}@if($backup->path)<span class="text-base-content/50 font-normal font-mono text-xs"> / {{ $backup->path }}</span>@endif</span>
                                            </dd>
                                        @endif

                                        @if($summaryWhen)
                                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-base-content/50">
                                                <x-icon name="o-clock" class="w-3.5 h-3.5" />
                                                {{ __('When') }}
                                            </dt>
                                            <dd class="font-semibold text-base-content">{{ $summaryWhen }}</dd>
                                        @endif

                                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-base-content/50">
                                            <x-icon name="o-archive-box" class="w-3.5 h-3.5" />
                                            {{ __('Keep') }}
                                        </dt>
                                        <dd class="font-semibold text-base-content">{{ $summaryKeep }}</dd>
                                    </dl>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Side: Connection / SSH / Agent --}}
        <div class="flex flex-col gap-4 lg:basis-1/3 lg:w-1/3">
            {{-- Connection --}}
            <div class="card card-border bg-base-100 shadow-sm overflow-hidden">
                <div class="flex items-center gap-2.5 border-b border-base-200 px-4 py-3">
                    <x-icon :name="$server->database_type->icon()" class="w-4 h-4" />
                    <h2 class="text-sm font-semibold">{{ __('Connection') }}</h2>
                </div>
                <ul class="list">
                    @if($isSqlite)
                        <li class="list-row">
                            <x-icon name="o-document" class="w-4 h-4 opacity-60" />
                            <div>
                                <div class="text-xs uppercase font-semibold opacity-60">{{ __('Database files') }}</div>
                                <div class="text-sm font-mono break-all">
                                    @foreach($server->resolveDatabaseNames() as $path)
                                        <div>{{ $path }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </li>
                    @else
                        <li class="list-row">
                            <x-icon name="o-wifi" class="w-4 h-4 opacity-60" />
                            <div>
                                <div class="text-xs uppercase font-semibold opacity-60">{{ __('Host / Port') }}</div>
                                <div class="text-sm font-mono">{{ $server->host }}<span class="opacity-50">:{{ $server->port }}</span></div>
                            </div>
                        </li>
                        @if($server->username)
                            <li class="list-row">
                                <x-icon name="o-key" class="w-4 h-4 opacity-60" />
                                <div>
                                    <div class="text-xs uppercase font-semibold opacity-60">{{ __('Username') }}</div>
                                    <div class="text-sm font-mono">{{ $server->username }}</div>
                                </div>
                            </li>
                        @endif
                        <li class="list-row">
                            <x-icon name="o-lock-closed" class="w-4 h-4 opacity-60" />
                            <div>
                                <div class="text-xs uppercase font-semibold opacity-60">{{ __('Password') }}</div>
                                <div class="text-sm font-mono tracking-widest opacity-60">{{ $server->password ? '••••••••' : '—' }}</div>
                            </div>
                        </li>
                        @if($server->database_type === DatabaseType::MYSQL)
                            <li class="list-row">
                                <x-icon name="o-shield-check" class="w-4 h-4 opacity-60" />
                                <div>
                                    <div class="text-xs uppercase font-semibold opacity-60">{{ __('SSL') }}</div>
                                    <div class="text-xs font-medium {{ $sslEnabled ? 'text-success' : 'opacity-60' }}">
                                        {{ $sslEnabled ? __('Enabled') : __('Disabled') }}
                                    </div>
                                </div>
                            </li>
                        @endif
                        @if($authSource)
                            <li class="list-row">
                                <x-icon name="o-identification" class="w-4 h-4 opacity-60" />
                                <div>
                                    <div class="text-xs uppercase font-semibold opacity-60">{{ __('Authentication DB') }}</div>
                                    <div class="text-sm font-mono">{{ $authSource }}</div>
                                </div>
                            </li>
                        @endif
                    @endif
                </ul>
            </div>

            {{-- Dump configuration --}}
            @if($showDumpCard)
                <div class="card card-border bg-base-100 shadow-sm overflow-hidden">
                    <div class="flex items-center gap-2.5 border-b border-base-200 px-4 py-3">
                        <x-icon name="o-command-line" class="w-4 h-4 opacity-60" />
                        <h2 class="text-sm font-semibold">{{ __('Dump configuration') }}</h2>
                    </div>
                    <ul class="list">
                        @if($isPostgres)
                            <li class="list-row">
                                <x-icon name="o-document-text" class="w-4 h-4 opacity-60" />
                                <div class="min-w-0">
                                    <div class="text-xs uppercase font-semibold opacity-60">{{ __('Format') }}</div>
                                    <div class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                        @if($dumpFormat === 'custom')
                                            <span class="badge badge-info badge-soft gap-1.5">
                                                <x-icon name="o-cube" class="w-3 h-3" />
                                                {{ __('Custom (pg_restore)') }}
                                            </span>
                                        @else
                                            <span class="badge badge-ghost gap-1.5">
                                                <x-icon name="o-document" class="w-3 h-3" />
                                                {{ __('Plain SQL (psql -f)') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endif
                        @if($dumpFlags)
                            <li class="list-row">
                                <x-icon name="o-adjustments-horizontal" class="w-4 h-4 opacity-60" />
                                <div class="min-w-0">
                                    <div class="text-xs uppercase font-semibold opacity-60">{{ __('Extra flags') }}</div>
                                    <code class="mt-1 inline-block text-xs font-mono break-all px-1.5 py-0.5 rounded bg-base-200">{{ $dumpFlags }}</code>
                                </div>
                            </li>
                        @endif
                    </ul>
                </div>
            @endif

            {{-- Agent --}}
            @if($agent)
                @php
                    $online = $agent->isOnline();
                    $neverConnected = $agent->last_heartbeat_at === null;
                @endphp
                <div class="card card-border bg-base-100 shadow-sm overflow-hidden">
                    <div class="flex items-center gap-2.5 border-b border-base-200 px-4 py-3">
                        <x-icon name="o-cpu-chip" class="w-4 h-4 opacity-60" />
                        <h2 class="text-sm font-semibold">{{ __('Agent') }}</h2>
                    </div>
                    <div class="p-4 space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium truncate">{{ $agent->name }}</span>
                            <span class="badge {{ $neverConnected ? 'badge-ghost' : ($online ? 'badge-success' : 'badge-error') }} badge-soft gap-1.5">
                                @if(! $neverConnected)
                                    <span class="status {{ $online ? 'status-success animate-pulse' : 'status-error' }}"></span>
                                @endif
                                {{ $neverConnected ? __('Never connected') : ($online ? __('Online') : __('Offline')) }}
                            </span>
                        </div>
                        @if($agent->last_heartbeat_at)
                            <p class="text-xs opacity-60">
                                {{ __('Last heartbeat :time', ['time' => $agent->last_heartbeat_at->diffForHumans()]) }}
                            </p>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ── Latest Jobs ── --}}
    <div class="mt-6">
        <livewire:dashboard.latest-jobs :serverId="$server->id" :key="'jobs-' . $server->id" />
    </div>

    {{-- DELETE CONFIRMATION MODAL --}}
    <x-delete-confirmation-modal :title="__('Delete Database Server')"
                                 :message="__('Are you sure you want to delete this database server? This action cannot be undone.')"
                                 onConfirm="delete"
                                 :showKeepFiles="$deleteSnapshotCount > 0"
                                 :snapshotCount="$deleteSnapshotCount" />

    {{-- RESTORE MODAL --}}
    <livewire:restore.modal />

    {{-- REDIS RESTORE INFO MODAL --}}
    <x-modal wire:model="showRedisRestoreModal" :title="__('Restore Redis / Valkey Snapshot')" class="backdrop-blur">
        <div class="space-y-4">
            <x-alert class="alert-info" icon="o-information-circle">
                <div>
                    <span class="font-bold">{{ __('Manual Restore Required') }}</span>
                    <p class="text-sm mt-1">
                        {{ __('Automated restore is not supported for Redis/Valkey. RDB snapshots must be restored manually.') }}
                    </p>
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

            <a href="{{ route('snapshots.index', ['serverFilter' => $server->id]) }}"
               class="btn btn-sm btn-outline gap-2" wire:navigate>
                <x-icon name="o-arrow-down-tray" class="w-4 h-4" />
                {{ __('View Backup Snapshots') }}
            </a>
        </div>

        <x-slot:actions>
            <x-button label="{{ __('Close') }}" @click="$wire.showRedisRestoreModal = false" />
        </x-slot:actions>
    </x-modal>
</div>
