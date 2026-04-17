@php
    use App\Enums\DatabaseSelectionMode;
    use App\Enums\DatabaseType;
    use App\Livewire\Forms\BackupForm;
    use App\Models\Backup;

    /** @var array<string, mixed> $backup */
    $serverType = DatabaseType::tryFrom($form->database_type);
    $isSqlite = $serverType === DatabaseType::SQLITE;
    $showDatabaseSelection = $serverType !== null
        && ! in_array($serverType, [DatabaseType::SQLITE, DatabaseType::REDIS], true);

    $resolvedPathPreview = BackupForm::resolvedPathPreview($backup);
    $summaryWhat = $serverType ? BackupForm::selectionSummary($backup, $serverType) : null;
    $summaryVolume = BackupForm::volumeLabel($backup, $volumes);
    $summarySchedule = BackupForm::scheduleLabel($backup, $schedules);
    $summaryHowLong = BackupForm::retentionSummary($backup);
    $summaryComplete = $serverType !== null && BackupForm::isComplete($backup, $serverType);

    $cardLabel = $summaryVolume && $summarySchedule
        ? implode(' · ', array_filter([$summarySchedule.' → '.$summaryVolume, $summaryWhat, $summaryHowLong]))
        : __('New backup configuration');

    $sqlitePaths = $isSqlite
        ? (! empty($backup['database_names']) ? $backup['database_names'] : [''])
        : [];
@endphp

@php
    $cardKey = ! empty($backup['id']) ? $backup['id'] : 'new-'.$index;
@endphp

<div
    wire:key="backup-card-{{ $cardKey }}"
    class="relative rounded-xl border border-base-300 bg-base-200/40 p-4 sm:p-6"
>
    {{-- Card header: display label + remove button --}}
    <div class="flex items-start justify-between gap-3 mb-5">
        <div class="flex items-center gap-2 min-w-0">
            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                <x-icon name="o-archive-box-arrow-down" class="w-4 h-4" />
            </span>
            <div>
                <p class="text-sm font-semibold">{{ $cardLabel }}</p>
                <p class="text-xs text-base-content/60">
                    {{ __('Backup #:num', ['num' => $position]) }}
                </p>
            </div>
        </div>
        @if(count($form->backups) > 1)
            <x-button
                wire:click="removeBackup({{ $index }})"
                icon="o-trash"
                class="btn-ghost btn-sm text-error shrink-0"
                tooltip="{{ __('Remove this backup configuration') }}"
                type="button"
            />
        @endif
    </div>

    <div class="space-y-6">
        {{-- ======================================================================== --}}
        {{-- Sub-group 1a — SQLite file paths (only for SQLite)                        --}}
        {{-- ======================================================================== --}}
        @if($isSqlite)
            <div class="space-y-3">
                <div class="flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-base-200 text-base-content/70">
                        <x-icon name="o-document" class="w-3.5 h-3.5" />
                    </span>
                    <span class="text-sm font-semibold text-base-content/80 tracking-tight">
                        {{ __('Database file paths') }}
                    </span>
                </div>

                <div class="space-y-2">
                    @foreach($sqlitePaths as $pathIndex => $path)
                        <div wire:key="backup-{{ $index }}-path-{{ $pathIndex }}" class="flex gap-2 items-center">
                            <div class="flex-1">
                                <x-input
                                    wire:model.live.debounce.400ms="form.backups.{{ $index }}.database_names.{{ $pathIndex }}"
                                    placeholder="{{ __('e.g., /var/data/database.sqlite') }}"
                                    type="text"
                                />
                            </div>
                            @if(count($sqlitePaths) > 1)
                                <x-button
                                    wire:click="removeDatabasePath({{ $index }}, {{ $pathIndex }})"
                                    icon="o-trash"
                                    class="btn-ghost btn-square btn-sm text-error"
                                    type="button"
                                />
                            @endif
                        </div>
                    @endforeach
                    <x-button
                        wire:click="addDatabasePath({{ $index }})"
                        icon="o-plus"
                        class="btn-ghost btn-sm"
                        :label="__('Add path')"
                        type="button"
                    />
                    <p class="text-xs opacity-50">
                        {{ $form->ssh_enabled ? __('Absolute paths on the remote SSH server') : __('Absolute paths to SQLite database files') }}
                    </p>
                </div>
            </div>
        @endif

        {{-- ======================================================================== --}}
        {{-- Sub-group 1 — What to back up (hidden for SQLite / Redis)                 --}}
        {{-- ======================================================================== --}}
        @if($showDatabaseSelection)
            <div class="space-y-3">
                <div class="flex items-center gap-2">
                    <span class="flex h-6 w-6 items-center justify-center rounded bg-base-200 text-base-content/70">
                        <x-icon name="o-circle-stack" class="w-3.5 h-3.5" />
                    </span>
                    <span class="text-sm font-semibold text-base-content/80 tracking-tight">
                        {{ __('What to back up') }}
                    </span>
                </div>

                <x-radio-card-group class="grid-cols-1 sm:grid-cols-3" :label="__('Database selection mode')">
                    <x-radio-card
                        :active="($backup['database_selection_mode'] ?? '') === DatabaseSelectionMode::All->value"
                        icon="o-circle-stack"
                        :label="__('All databases')"
                        :hint="__('Back up every user database')"
                        :value="DatabaseSelectionMode::All->value"
                        wire:model.live="form.backups.{{ $index }}.database_selection_mode"
                    />
                    <x-radio-card
                        :active="($backup['database_selection_mode'] ?? '') === DatabaseSelectionMode::Selected->value"
                        icon="o-check-badge"
                        :label="__('Selected')"
                        :hint="__('Pick specific databases')"
                        :value="DatabaseSelectionMode::Selected->value"
                        wire:model.live="form.backups.{{ $index }}.database_selection_mode"
                    />
                    <x-radio-card
                        :active="($backup['database_selection_mode'] ?? '') === DatabaseSelectionMode::Pattern->value"
                        icon="bi.regex"
                        :label="__('Pattern')"
                        :hint="__('Filter by regex')"
                        :value="DatabaseSelectionMode::Pattern->value"
                        wire:model.live="form.backups.{{ $index }}.database_selection_mode"
                    />
                </x-radio-card-group>

                {{-- All Databases sub-panel --}}
                @if(($backup['database_selection_mode'] ?? '') === DatabaseSelectionMode::All->value)
                    <div class="rounded-lg border border-base-300 bg-base-100 p-4 flex items-start gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-info/10 text-info">
                            <x-icon name="o-information-circle" class="w-3.5 h-3.5" />
                        </span>
                        <div class="flex-1 min-w-0 space-y-2">
                            <p class="text-sm text-base-content/80 leading-relaxed">
                                {{ __('All user databases will be backed up. System databases are automatically excluded.') }}
                            </p>
                            @if(count($form->availableDatabases) > 0)
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-base-content/60">{{ __('Detected databases:') }}</span>
                                    <span class="badge badge-ghost badge-sm font-mono tabular-nums">{{ count($form->availableDatabases) }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Selected Databases sub-panel --}}
                @if(($backup['database_selection_mode'] ?? '') === DatabaseSelectionMode::Selected->value)
                    <div class="rounded-lg border border-base-300 bg-base-100 p-4">
                        @if($form->loadingDatabases)
                            <div class="flex items-center gap-2 text-base-content/70">
                                <x-loading class="loading-spinner loading-sm" />
                                {{ __('Loading databases...') }}
                            </div>
                        @elseif(count($form->availableDatabases) > 0)
                            <x-choices-offline
                                wire:model.live="form.backups.{{ $index }}.database_names"
                                :label="__('Select Databases')"
                                :options="$form->availableDatabases"
                                :hint="__('Select one or more databases to backup')"
                                searchable
                            />
                        @else
                            <x-input
                                wire:model.live.debounce.400ms="form.backups.{{ $index }}.database_names_input"
                                :label="__('Database Names')"
                                placeholder="{{ __('e.g., db1, db2, db3') }}"
                                :hint="__('Enter database names separated by commas')"
                                type="text"
                                required
                            />
                        @endif
                    </div>
                @endif

                {{-- Pattern sub-panel --}}
                @if(($backup['database_selection_mode'] ?? '') === DatabaseSelectionMode::Pattern->value)
                    <div class="rounded-lg border border-base-300 bg-base-100 p-4 space-y-4">
                        {{-- Regex input with /…/i delimiters --}}
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="text-xs font-medium text-base-content/70">{{ __('Include Pattern') }}</label>
                                <span class="font-mono text-[10px] text-base-content/40">{{ __('regex · case-insensitive') }}</span>
                            </div>
                            <div class="flex items-center gap-0">
                                <span class="bg-base-200 border border-r-0 border-base-300 rounded-l-lg px-3 py-2 text-base-content/50 font-mono text-sm select-none">/</span>
                                <input
                                    type="text"
                                    wire:model.live.debounce.300ms="form.backups.{{ $index }}.database_include_pattern"
                                    class="input input-bordered rounded-none flex-1 font-mono text-sm"
                                    placeholder="{{ __('e.g., ^prod_ or ^(?!test_)') }}"
                                />
                                <span class="bg-base-200 border border-l-0 border-base-300 rounded-r-lg px-3 py-2 text-base-content/50 font-mono text-sm select-none">/i</span>
                            </div>
                        </div>

                        {{-- Examples with descriptions --}}
                        <div class="space-y-1.5">
                            <span class="text-xs text-base-content/60 font-medium">{{ __('Examples:') }}</span>
                            <div class="space-y-1 text-xs text-base-content/60">
                                <div class="flex items-baseline gap-2">
                                    <code class="bg-base-100 border border-base-300 px-1.5 py-0.5 rounded font-mono shrink-0">^prod_</code>
                                    <span>{{ __('matches databases starting with prod_') }}</span>
                                </div>
                                <div class="flex items-baseline gap-2">
                                    <code class="bg-base-100 border border-base-300 px-1.5 py-0.5 rounded font-mono shrink-0">^(?!test_)</code>
                                    <span>{{ __('excludes databases starting with test_') }}</span>
                                </div>
                                <div class="flex items-baseline gap-2">
                                    <code class="bg-base-100 border border-base-300 px-1.5 py-0.5 rounded font-mono shrink-0">^(?!.*preprod)</code>
                                    <span>{{ __('excludes databases containing preprod') }}</span>
                                </div>
                            </div>
                        </div>

                        @error('form.backups.'.$index.'.database_include_pattern')
                            <x-alert class="alert-error" icon="o-x-circle">
                                {{ $message }}
                            </x-alert>
                        @enderror

                        {{-- Live preview --}}
                        @if(count($form->availableDatabases) > 0)
                            @php
                                $currentPattern = $backup['database_include_pattern'] ?? '';
                                $hasPattern = $currentPattern !== '';
                                $isValidPattern = ! $hasPattern || \App\Models\DatabaseServer::isValidDatabasePattern($currentPattern);
                                $filteredDbs = $hasPattern && $isValidPattern ? $form->getFilteredDatabases($currentPattern) : [];
                            @endphp

                            @if($hasPattern && ! $isValidPattern)
                                <x-alert class="alert-warning" icon="o-exclamation-triangle">
                                    {{ __('Invalid regular expression pattern.') }}
                                </x-alert>
                            @else
                                <div class="rounded-lg border border-base-300 bg-base-200/40 overflow-hidden">
                                    <div class="flex items-center justify-between bg-base-200/60 px-3 py-2 border-b border-base-300">
                                        <span class="text-xs font-semibold text-base-content/70">{{ __('Preview') }}</span>
                                        <span class="text-xs text-base-content/60 tabular-nums">
                                            @if($hasPattern)
                                                <span class="font-semibold text-success">{{ count($filteredDbs) }}</span><span class="text-base-content/50">/{{ count($form->availableDatabases) }} {{ __('matched') }}</span>
                                            @else
                                                {{ count($form->availableDatabases) }} {{ __('databases') }}
                                            @endif
                                        </span>
                                    </div>
                                    <div class="max-h-48 overflow-y-auto divide-y divide-base-200">
                                        @foreach($form->availableDatabases as $db)
                                            @php $matched = $hasPattern && in_array($db['name'], $filteredDbs, true); @endphp
                                            <div class="flex items-center gap-2.5 px-3 py-2 text-sm transition-opacity {{ $hasPattern && ! $matched ? 'opacity-35' : '' }}">
                                                @if($hasPattern && $matched)
                                                    <x-icon name="s-check-circle" class="w-4 h-4 text-success shrink-0" />
                                                @elseif($hasPattern)
                                                    <x-icon name="o-minus-circle" class="w-4 h-4 text-base-content/40 shrink-0" />
                                                @else
                                                    <span class="h-4 w-4 shrink-0 rounded-full border border-base-300"></span>
                                                @endif
                                                <span class="font-mono text-xs {{ $hasPattern && $matched ? 'font-medium' : 'text-base-content/70' }}">{{ $db['name'] }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @else
                            <x-alert class="alert-{{ $form->hasAgent() ? 'info' : 'warning' }}" icon="{{ $form->hasAgent() ? 'o-information-circle' : 'o-exclamation-triangle' }}">
                                {{ $form->hasAgent() ? __('Pattern preview is not available for agent-managed servers.') : __('Test connection to see pattern preview.') }}
                            </x-alert>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- ======================================================================== --}}
        {{-- Sub-group 2 — Where to store                                              --}}
        {{-- ======================================================================== --}}
        <div class="space-y-3 {{ $showDatabaseSelection ? 'pt-6 border-t border-base-200' : '' }}">
            <div class="flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded bg-base-200 text-base-content/70">
                    <x-icon name="o-server-stack" class="w-3.5 h-3.5" />
                </span>
                <span class="text-sm font-semibold text-base-content/80 tracking-tight">
                    {{ __('Where to store') }}
                </span>
            </div>

            <x-select
                wire:model.live="form.backups.{{ $index }}.volume_id"
                :label="__('Storage Volume')"
                :options="$volumeOptions"
                placeholder="{{ __('Select a storage volume') }}"
                placeholder-value=""
                icon="o-server-stack"
                required
            >
                <x-slot:append>
                    <x-button
                        wire:click="refreshVolumes"
                        icon="o-arrow-path"
                        class="btn-ghost join-item"
                        tooltip-bottom="{{ __('Refresh volume list') }}"
                        spinner
                    />
                    <x-button
                        link="{{ route('volumes.create') }}"
                        icon="o-plus"
                        class="btn-ghost join-item"
                        tooltip-bottom="{{ __('Create new volume') }}"
                        external
                    />
                </x-slot:append>
            </x-select>

            <div>
                <x-input
                    wire:model.live.debounce.300ms="form.backups.{{ $index }}.path"
                    :label="__('Subfolder Path')"
                    placeholder="{{ __('e.g., backups/{year}/{month}/{day}') }}"
                    type="text"
                    icon="o-folder"
                />
                <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                    <span class="text-xs text-base-content/50">{{ __('Available variables:') }}</span>
                    @foreach(['{year}', '{month}', '{day}'] as $variable)
                        <code class="inline-flex items-center rounded border border-base-300 bg-base-200/60 px-1.5 py-0.5 font-mono text-[11px] text-base-content/70">{{ $variable }}</code>
                    @endforeach
                </div>
                @if($resolvedPathPreview)
                    <div class="mt-2 flex items-center gap-2 rounded-md bg-base-200/40 border border-base-200 px-3 py-1.5">
                        <x-icon name="o-arrow-right" class="w-3.5 h-3.5 text-base-content/40 shrink-0" />
                        <span class="font-mono text-xs text-base-content/70">{{ $resolvedPathPreview }}</span>
                        <span class="ml-1 text-xs text-base-content/40">{{ __('(resolved today)') }}</span>
                    </div>
                @endif
            </div>
        </div>

        {{-- ======================================================================== --}}
        {{-- Sub-group 3 — When to run                                                 --}}
        {{-- ======================================================================== --}}
        <div class="space-y-3 pt-6 border-t border-base-200">
            <div class="flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded bg-base-200 text-base-content/70">
                    <x-icon name="o-clock" class="w-3.5 h-3.5" />
                </span>
                <span class="text-sm font-semibold text-base-content/80 tracking-tight">
                    {{ __('When to run') }}
                </span>
            </div>

            <x-select
                wire:model.live="form.backups.{{ $index }}.backup_schedule_id"
                :label="__('Backup Schedule')"
                :options="$scheduleOptions"
                placeholder="{{ __('Select a schedule') }}"
                placeholder-value=""
                icon="o-clock"
                required
            >
                <x-slot:append>
                    <x-button
                        wire:click="refreshSchedules"
                        icon="o-arrow-path"
                        class="btn-ghost join-item"
                        tooltip-bottom="{{ __('Refresh schedule list') }}"
                        spinner
                    />
                    <x-button
                        link="{{ route('configuration.index') }}"
                        icon="o-cog-6-tooth"
                        class="btn-ghost join-item"
                        tooltip-bottom="{{ __('Manage schedules') }}"
                        external
                    />
                </x-slot:append>
            </x-select>
        </div>

        {{-- ======================================================================== --}}
        {{-- Sub-group 4 — How long to keep                                            --}}
        {{-- ======================================================================== --}}
        <div class="space-y-3 pt-6 border-t border-base-200">
            <div class="flex items-center gap-2">
                <span class="flex h-6 w-6 items-center justify-center rounded bg-base-200 text-base-content/70">
                    <x-icon name="o-archive-box" class="w-3.5 h-3.5" />
                </span>
                <span class="text-sm font-semibold text-base-content/80 tracking-tight">
                    {{ __('How long to keep') }}
                </span>
            </div>

            <x-radio-card-group class="grid-cols-1 sm:grid-cols-3" :label="__('Retention Policy')">
                <x-radio-card
                    :active="($backup['retention_policy'] ?? '') === Backup::RETENTION_DAYS"
                    icon="o-calendar-days"
                    :label="__('Days')"
                    :hint="__('Keep the last N days')"
                    :value="Backup::RETENTION_DAYS"
                    wire:model.live="form.backups.{{ $index }}.retention_policy"
                />
                <x-radio-card
                    :active="($backup['retention_policy'] ?? '') === Backup::RETENTION_GFS"
                    icon="o-square-3-stack-3d"
                    :label="__('GFS')"
                    :hint="__('Tiered retention')"
                    :value="Backup::RETENTION_GFS"
                    wire:model.live="form.backups.{{ $index }}.retention_policy"
                />
                <x-radio-card
                    :active="($backup['retention_policy'] ?? '') === Backup::RETENTION_FOREVER"
                    icon="o-arrow-path-rounded-square"
                    :label="__('Forever')"
                    :hint="__('Keep everything')"
                    :value="Backup::RETENTION_FOREVER"
                    wire:model.live="form.backups.{{ $index }}.retention_policy"
                />
            </x-radio-card-group>

            {{-- Days sub-panel --}}
            @if(($backup['retention_policy'] ?? '') === Backup::RETENTION_DAYS)
                <div class="rounded-lg border border-base-300 bg-base-100 p-4 space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center rounded-md border border-base-300 bg-base-100 overflow-hidden">
                            <input
                                type="number"
                                wire:model.live.debounce.300ms="form.backups.{{ $index }}.retention_days"
                                min="1"
                                max="365"
                                class="w-20 bg-transparent px-3 py-2 text-sm font-semibold text-base-content outline-none tabular-nums text-center"
                            />
                            <span class="border-l border-base-300 bg-base-200/60 px-2.5 py-2 text-xs text-base-content/60 select-none">{{ __('days') }}</span>
                        </div>
                        <span class="text-sm text-base-content/60">{{ __('Retention period') }}</span>
                    </div>

                    <div class="space-y-2">
                        <div class="flex justify-between gap-2">
                            @foreach([1, 7, 14, 30, 90, 365] as $mark)
                                <button
                                    type="button"
                                    wire:click="$set('form.backups.{{ $index }}.retention_days', {{ $mark }})"
                                    class="text-xs tabular-nums font-mono transition-colors {{ (int) ($backup['retention_days'] ?? 0) === $mark ? 'font-bold text-primary' : 'text-base-content/40 hover:text-base-content/70' }}"
                                >
                                    {{ $mark }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <p class="text-xs text-base-content/60 border-t border-base-200 pt-3 leading-relaxed">
                        {{ trans_choice('{1} Snapshots older than :count day will be deleted automatically during the next cleanup run.|[2,*] Snapshots older than :count days will be deleted automatically during the next cleanup run.', (int) ($backup['retention_days'] ?? 14), ['count' => (int) ($backup['retention_days'] ?? 14)]) }}
                    </p>
                </div>
            @endif

            {{-- GFS sub-panel --}}
            @if(($backup['retention_policy'] ?? '') === Backup::RETENTION_GFS)
                <div class="rounded-lg border border-base-300 bg-base-100 p-4 space-y-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-2.5">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-info/10 text-info">
                                <x-icon name="o-information-circle" class="w-3.5 h-3.5" />
                            </span>
                            <p class="text-sm text-base-content/75 leading-relaxed">
                                {{ __('Grandfather-Father-Son keeps a tiered set of snapshots across multiple time horizons. Set any tier to 0 to disable it.') }}
                            </p>
                        </div>
                        <x-button
                            :label="__('Docs')"
                            link="https://david-crty.github.io/databasement/user-guide/backups/#retention-policies"
                            external
                            class="btn-ghost btn-sm shrink-0"
                            icon="o-book-open"
                        />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                        <div class="flex flex-col gap-2 rounded-lg border border-base-300 bg-base-100 p-3">
                            <div class="flex items-center gap-1.5 text-xs font-semibold text-info">
                                <x-icon name="o-calendar-days" class="w-3.5 h-3.5" />
                                {{ __('Daily') }}
                            </div>
                            <input
                                type="number"
                                wire:model.live.debounce.400ms="form.backups.{{ $index }}.gfs_keep_daily"
                                min="0"
                                max="90"
                                placeholder="0"
                                class="w-full rounded-md border border-base-300 bg-base-200/30 px-2.5 py-1.5 text-center text-sm font-semibold tabular-nums outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                            />
                            <span class="text-xs text-base-content/50 text-center leading-tight">{{ __('Keep last N daily snapshots') }}</span>
                        </div>
                        <div class="flex flex-col gap-2 rounded-lg border border-base-300 bg-base-100 p-3">
                            <div class="flex items-center gap-1.5 text-xs font-semibold text-primary">
                                <x-icon name="o-calendar" class="w-3.5 h-3.5" />
                                {{ __('Weekly') }}
                            </div>
                            <input
                                type="number"
                                wire:model.live.debounce.400ms="form.backups.{{ $index }}.gfs_keep_weekly"
                                min="0"
                                max="52"
                                placeholder="0"
                                class="w-full rounded-md border border-base-300 bg-base-200/30 px-2.5 py-1.5 text-center text-sm font-semibold tabular-nums outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                            />
                            <span class="text-xs text-base-content/50 text-center leading-tight">{{ __('Keep last N weekly snapshots') }}</span>
                        </div>
                        <div class="flex flex-col gap-2 rounded-lg border border-base-300 bg-base-100 p-3">
                            <div class="flex items-center gap-1.5 text-xs font-semibold text-success">
                                <x-icon name="o-calendar" class="w-3.5 h-3.5" />
                                {{ __('Monthly') }}
                            </div>
                            <input
                                type="number"
                                wire:model.live.debounce.400ms="form.backups.{{ $index }}.gfs_keep_monthly"
                                min="0"
                                max="24"
                                placeholder="0"
                                class="w-full rounded-md border border-base-300 bg-base-200/30 px-2.5 py-1.5 text-center text-sm font-semibold tabular-nums outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                            />
                            <span class="text-xs text-base-content/50 text-center leading-tight">{{ __('Keep last N monthly snapshots') }}</span>
                        </div>
                    </div>

                    <p class="text-xs text-base-content/50 border-t border-base-200 pt-3 leading-relaxed">
                        {{ __('Snapshots matching multiple tiers are counted only once toward storage quotas.') }}
                    </p>
                </div>
            @endif

            {{-- Forever sub-panel --}}
            @if(($backup['retention_policy'] ?? '') === Backup::RETENTION_FOREVER)
                <div class="flex items-start gap-3 rounded-lg border border-warning/30 bg-warning/5 px-4 py-3.5">
                    <x-icon name="o-exclamation-triangle" class="w-4 h-4 shrink-0 text-warning mt-0.5" />
                    <div>
                        <p class="text-sm font-semibold leading-snug">{{ __('Snapshots will be kept indefinitely') }}</p>
                        <p class="mt-0.5 text-xs text-base-content/60 leading-relaxed">
                            {{ __('No automatic cleanup will run. Make sure you have sufficient storage capacity and a manual retention strategy in place.') }}
                        </p>
                    </div>
                </div>
            @endif
        </div>

        {{-- ======================================================================== --}}
        {{-- Live summary callout                                                       --}}
        {{-- ======================================================================== --}}
        <div class="pt-6 border-t border-base-200">
            @if($summaryComplete)
                <div class="rounded-lg border border-primary/20 bg-primary/5 px-4 py-3.5">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="flex h-6 w-6 items-center justify-center rounded-md bg-primary/10 text-primary">
                            <x-icon name="o-archive-box-arrow-down" class="w-3.5 h-3.5" />
                        </span>
                        <span class="text-xs font-semibold uppercase tracking-wider text-primary/70">{{ __('Summary') }}</span>
                    </div>

                    <dl class="grid gap-y-2 gap-x-4 text-sm" style="grid-template-columns: auto 1fr;">
                        @if($summaryWhat)
                            <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-base-content/50">
                                <x-icon name="o-circle-stack" class="w-3.5 h-3.5" />
                                {{ __('What') }}
                            </dt>
                            <dd class="font-semibold text-base-content">{{ $summaryWhat }}</dd>
                        @endif

                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-base-content/50">
                            <x-icon name="o-server-stack" class="w-3.5 h-3.5" />
                            {{ __('Where') }}
                        </dt>
                        <dd class="font-semibold text-base-content">
                            {{ $summaryVolume }}@if($resolvedPathPreview)<span class="text-base-content/50 font-normal font-mono text-xs"> / {{ $resolvedPathPreview }}</span>@endif
                        </dd>

                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-base-content/50">
                            <x-icon name="o-clock" class="w-3.5 h-3.5" />
                            {{ __('When') }}
                        </dt>
                        <dd class="font-semibold text-base-content">{{ $summarySchedule }}</dd>

                        <dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-base-content/50">
                            <x-icon name="o-archive-box" class="w-3.5 h-3.5" />
                            {{ __('Keep') }}
                        </dt>
                        <dd class="font-semibold text-base-content">{{ $summaryHowLong }}</dd>
                    </dl>
                </div>
            @else
                <div class="flex items-start gap-3 rounded-lg border border-warning/30 bg-warning/5 px-4 py-3.5">
                    <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-warning/15 text-warning">
                        <x-icon name="o-exclamation-triangle" class="w-4 h-4" />
                    </span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold leading-snug">{{ __('Configuration incomplete') }}</p>
                        <p class="mt-0.5 text-xs text-base-content/60 leading-relaxed">
                            {{ __('Fill in the fields above to see a summary of this backup plan.') }}
                        </p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
