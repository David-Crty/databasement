<div>
    @php
        use App\Enums\DatabaseSelectionMode;
        use App\Enums\DatabaseType;
        use App\Enums\NotificationChannelSelection;
        use App\Enums\NotificationTrigger;
        use App\Livewire\DatabaseServer\BackupForm;

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

                        <div class="mt-1.5 flex items-center gap-1 text-xs text-base-content/50">
                            <span class="opacity-70">{{ __('ID') }}</span>
                            <code class="font-mono break-all">{{ $server->id }}</code>
                            <x-button
                                icon="o-clipboard-document"
                                class="btn-ghost btn-xs btn-circle shrink-0"
                                x-clipboard="'{{ $server->id }}'"
                                x-on:clipboard-copied="successToast('{{ __('Copied to clipboard!') }}')"
                                :tooltip="__('Copy ID')"
                            />
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="badge badge-ghost gap-1.5 whitespace-nowrap">
                                <x-icon name="o-circle-stack" class="w-3.5 h-3.5" />
                                {{ $server->database_type->label() }}
                            </span>

                            @if($sshConfig)
                                <span class="badge badge-warning gap-1.5 whitespace-nowrap max-w-full">
                                    <x-icon name="o-shield-check" class="w-3.5 h-3.5 shrink-0" />
                                    <span class="truncate">
                                        {{ __('SSH tunnel') }}
                                        <code class="font-mono">{{ $sshConfig->username . '@' . $sshConfig->host . ':' . $sshConfig->port }}</code>
                                    </span>
                                </span>
                            @endif

                            @if($agent)
                                @php $online = $agent->isOnline(); @endphp
                                <span class="badge {{ $online ? 'badge-success' : 'badge-error' }} gap-1.5 whitespace-nowrap max-w-full">
                                    <x-icon :name="$online ? 'o-signal' : 'o-signal-slash'" class="w-3.5 h-3.5 shrink-0" />
                                    <span class="truncate">{{ __('Agent') }}: {{ $agent->name }}</span>
                                    <span class="status {{ $online ? 'status-success animate-pulse' : 'status-error' }} shrink-0"></span>
                                </span>
                            @endif

                            @if($server->backups_enabled)
                                <span class="badge badge-success gap-1.5 whitespace-nowrap">
                                    <x-icon name="o-check-circle" class="w-3.5 h-3.5" />
                                    {{ trans_choice('{0} Backups enabled (no config)|{1} Backups enabled (:count config)|[2,*] Backups enabled (:count configs)', $server->backups->count(), ['count' => $server->backups->count()]) }}
                                </span>
                            @else
                                <span class="badge badge-warning gap-1.5 whitespace-nowrap">
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
                    @if($canAdminer && $server->supportsAdminer())
                        <x-button :label="__('Browse')" icon="o-table-cells" wire:click="openAdminer" spinner
                                  class="btn-outline btn-accent btn-sm" />
                    @endif
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
                        <x-button icon="o-trash" wire:click="confirmDelete"
    spinner tooltip-left="{{ __('Delete') }}"
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
                        <span class="badge badge-warning badge-sm">{{ __('Disabled') }}</span>
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
                                    $summaryWhere = $backup->volumes->pluck('name')->implode(', ') ?: null;
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
                                            <x-summary-term icon="o-circle-stack" :label="__('What')" />
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

                                        @if(! empty($backup->excluded_tables))
                                            <x-summary-term icon="o-no-symbol" :label="__('Excluded')" />
                                            <dd class="flex flex-wrap items-center gap-1.5">
                                                @foreach($backup->excluded_tables as $excludedTable)
                                                    <code class="text-xs font-mono break-all px-1.5 py-0.5 rounded bg-base-200">{{ $excludedTable }}</code>
                                                @endforeach
                                            </dd>
                                        @endif

                                        @if($summaryWhere)
                                            <x-summary-term icon="o-server-stack" :label="__('Where')" />
                                            <dd class="font-semibold text-base-content inline-flex items-center gap-1.5">
                                                @if($backup->volumes->isNotEmpty())
                                                    <x-volume-type-icon :type="$backup->volumes->first()->type" class="w-3.5 h-3.5 opacity-70" />
                                                @endif
                                                <span>{{ $summaryWhere }}@if($backup->path)<span class="text-base-content/50 font-normal font-mono text-xs"> / {{ $backup->path }}</span>@endif</span>
                                            </dd>
                                        @endif

                                        @if($summaryWhen)
                                            <x-summary-term icon="o-clock" :label="__('When')" />
                                            <dd class="font-semibold text-base-content">{{ $summaryWhen }}</dd>
                                        @endif

                                        <x-summary-term icon="o-archive-box" :label="__('Keep')" />
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
                        <x-detail-row icon="o-document" :label="__('Database files')">
                            <div class="text-sm font-mono break-all">
                                @foreach($server->resolveDatabaseNames() as $path)
                                    <div>{{ $path }}</div>
                                @endforeach
                            </div>
                        </x-detail-row>
                    @else
                        <x-detail-row icon="o-wifi" :label="__('Host / Port')">
                            <div class="text-sm font-mono">{{ $server->host }}<span class="opacity-50">:{{ $server->port }}</span></div>
                        </x-detail-row>
                        @if($server->username)
                            <x-detail-row icon="o-key" :label="__('Username')">
                                <div class="text-sm font-mono">{{ $server->username }}</div>
                            </x-detail-row>
                        @endif
                        <x-detail-row icon="o-lock-closed" :label="__('Password')">
                            <div class="text-sm font-mono tracking-widest opacity-60">{{ $server->password ? '••••••••' : '—' }}</div>
                        </x-detail-row>
                        @if($server->database_type === DatabaseType::MYSQL)
                            <x-detail-row icon="o-shield-check" :label="__('SSL')">
                                <div class="text-xs font-medium {{ $sslEnabled ? 'text-success' : 'opacity-60' }}">
                                    {{ $sslEnabled ? __('Enabled') : __('Disabled') }}
                                </div>
                            </x-detail-row>
                        @endif
                        @if($authSource)
                            <x-detail-row icon="o-identification" :label="__('Authentication DB')">
                                <div class="text-sm font-mono">{{ $authSource }}</div>
                            </x-detail-row>
                        @endif
                    @endif
                </ul>
            </div>

            {{-- Dump configuration --}}
            @if($showDumpCard)
                <div class="card card-border bg-base-100 shadow-sm overflow-hidden">
                    <x-detail-card-header icon="o-command-line" :title="__('Dump configuration')" />
                    <ul class="list">
                        @if($isPostgres)
                            <x-detail-row icon="o-document-text" :label="__('Format')">
                                <div class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                    @if($dumpFormat === 'custom')
                                        <span class="badge badge-info gap-1.5">
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
                            </x-detail-row>
                        @endif
                        @if($dumpFlags)
                            <x-detail-row icon="o-adjustments-horizontal" :label="__('Extra flags')">
                                <code class="mt-1 inline-block text-xs font-mono break-all px-1.5 py-0.5 rounded bg-base-200">{{ $dumpFlags }}</code>
                            </x-detail-row>
                        @endif
                    </ul>
                </div>
            @endif

            {{-- Agent --}}
            @if($agent)
                <div class="card card-border bg-base-100 shadow-sm overflow-hidden">
                    <x-detail-card-header icon="o-cpu-chip" :title="__('Agent')" />
                    <div class="p-4 space-y-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium truncate">{{ $agent->name }}</span>
                            <x-agent-status-indicator :status="$agent->connectionStatus()" />
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

    {{-- ADMINER MODAL --}}
    <livewire:database-server.adminer-modal />

    {{-- REDIS RESTORE INFO MODAL --}}
    @include('partials.redis-restore-modal', ['serverId' => $server->id])
</div>
