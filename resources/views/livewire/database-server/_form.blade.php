@props(['form', 'submitLabel' => 'Save', 'cancelRoute' => 'database-servers.index', 'isEdit' => false])

@php
use App\Enums\DatabaseSelectionMode;
use App\Enums\DatabaseType;
@endphp

<x-form wire:submit="save" class="space-y-6">
    <!-- Section 1: Basic Information -->
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-3 sm:p-8">
            <div class="flex items-center gap-3 mb-4">
                <span class="badge badge-primary badge-lg font-bold">1</span>
                <h3 class="card-title text-lg">{{ __('Basic Information') }}</h3>
            </div>

            <div class="space-y-4">
                <x-input
                    wire:model="form.name"
                    label="{{ __('Server Name') }}"
                    placeholder="{{ __('e.g., Production MySQL Server') }}"
                    hint="{{ __('A friendly name to identify this server') }}"
                    type="text"
                    required
                />

                <x-textarea
                    wire:model="form.description"
                    label="{{ __('Description') }}"
                    placeholder="{{ __('Brief description of this database server') }}"
                    :hint="__('Notes for your team about this server\'s purpose')"
                    rows="2"
                />

                @php $agentOptions = $form->getAgentOptions(); @endphp
                @if(count($agentOptions) > 0 || $form->hasAgent())
                    <div class="border border-base-300 rounded-lg bg-base-200">
                        <!-- Toggle Header -->
                        <label class="flex items-start gap-3 p-4 cursor-pointer select-none">
                            <x-toggle
                                wire:model.live="form.use_agent"
                                class="toggle-primary"
                            />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-medium">{{ __('Use a remote agent') }}</span>
                                    <span class="badge badge-ghost badge-sm text-base-content/50 font-normal">{{ __('Optional') }}</span>
                                </div>
                                <p class="text-xs text-base-content/50 mt-0.5 leading-relaxed">
                                    {{ __('Route backups through an agent installed on a server inside your private network or behind a firewall.') }}
                                </p>
                            </div>
                        </label>

                        <!-- Agent Selection (shown only when enabled) -->
                        @if($form->use_agent)
                            <div class="border-t border-base-300 bg-base-100 p-4 rounded-b-lg space-y-3">
                                <x-select
                                    wire:model.live="form.agent_id"
                                    :label="__('Agent')"
                                    :options="$agentOptions"
                                    :placeholder="__('Select an agent')"
                                    placeholder-value=""
                                />

                                @if($form->hasAgent())
                                    @php $selectedAgent = $form->getSelectedAgent(); @endphp
                                    @if($selectedAgent)
                                        <div class="flex items-center gap-2 text-sm">
                                            @if($selectedAgent->isOnline())
                                                <span class="badge badge-success badge-sm gap-1">
                                                    <span class="w-2 h-2 rounded-full bg-success animate-pulse"></span>
                                                    {{ __('Online') }}
                                                </span>
                                                <span class="text-base-content/70">{{ __('Last heartbeat :time', ['time' => $selectedAgent->last_heartbeat_at->diffForHumans()]) }}</span>
                                            @elseif($selectedAgent->last_heartbeat_at)
                                                <span class="badge badge-warning badge-sm gap-1">
                                                    <span class="w-2 h-2 rounded-full bg-warning"></span>
                                                    {{ __('Offline') }}
                                                </span>
                                                <span class="text-base-content/70">{{ __('Last heartbeat :time', ['time' => $selectedAgent->last_heartbeat_at->diffForHumans()]) }}</span>
                                            @else
                                                <span class="badge badge-ghost badge-sm">{{ __('Never connected') }}</span>
                                            @endif
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Section 2: Connection Details -->
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body p-3 sm:p-8">
            <div class="flex items-center gap-3 mb-4">
                <span class="badge badge-primary badge-lg font-bold">2</span>
                <h3 class="card-title text-lg">{{ __('Connection Details') }}</h3>
            </div>

            <div class="space-y-4">
                <!-- Database Type Selection -->
                <div>
                    <label class="label label-text font-semibold mb-2">{{ __('Database Type') }}</label>
                    <x-radio-card-group class="grid-cols-2 sm:grid-cols-5" :label="__('Database Type')">
                        @foreach(DatabaseType::cases() as $dbType)
                            <x-radio-card
                                :active="$form->database_type === $dbType->value"
                                :icon="$dbType->icon()"
                                :label="$dbType->label()"
                                :value="$dbType->value"
                                wire:model.live="form.database_type"
                            />
                        @endforeach
                    </x-radio-card-group>
                </div>

                @if($form->database_type)
                    @include('livewire.database-server._ssh-tunnel-config', ['form' => $form, 'isEdit' => $isEdit])

                    @if($form->isSqlite())
                        <!-- SQLite Paths -->
                        <div class="space-y-3">
                            <label class="label label-text font-semibold">{{ __('Database File Paths') }}</label>
                            @foreach($form->database_names as $index => $path)
                                <div wire:key="database-path-{{ $index }}" class="flex gap-2 items-center">
                                    <div class="flex-1">
                                        <x-input
                                            wire:model="form.database_names.{{ $index }}"
                                            placeholder="{{ __('e.g., /var/data/database.sqlite') }}"
                                            type="text"
                                        />
                                    </div>
                                    @if(count($form->database_names) > 1)
                                        <x-button
                                            wire:click="removeDatabasePath({{ $index }})"
                                            icon="o-trash"
                                            class="btn-ghost btn-square btn-sm text-error"
                                            type="button"
                                        />
                                    @endif
                                </div>
                            @endforeach
                            <x-button
                                wire:click="addDatabasePath"
                                icon="o-plus"
                                class="btn-ghost btn-sm"
                                :label="__('Add path')"
                                type="button"
                            />
                            <p class="text-xs opacity-50">
                                {{ $form->ssh_enabled ? __('Absolute paths on the remote SSH server') : __('Absolute paths to SQLite database files') }}
                            </p>
                        </div>
                    @else
                        <!-- Client-server database connection fields -->
                        <div class="grid gap-4 md:grid-cols-2">
                            <x-input
                                wire:model="form.host"
                                label="{{ __('Host') }}"
                                placeholder="{{ __('e.g., localhost or 192.168.1.100') }}"
                                type="text"
                                required
                            />

                            <x-input
                                wire:model="form.port"
                                label="{{ __('Port') }}"
                                placeholder="{{ __('e.g., 3306') }}"
                                type="number"
                                min="1"
                                max="65535"
                                required
                            />
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <x-input
                                wire:model="form.username"
                                label="{{ __('Username') }}"
                                placeholder="{{ $form->hasOptionalCredentials() ? __('Optional (for authenticated servers)') : __('Database username') }}"
                                type="text"
                                :required="!$form->hasOptionalCredentials()"
                                autocomplete="off"
                            />

                            <x-password
                                wire:model="form.password"
                                label="{{ __('Password') }}"
                                placeholder="{{ $isEdit ? __('Leave blank to keep current') : __('Database password') }}"
                                :required="!$isEdit && !$form->hasOptionalCredentials()"
                                autocomplete="off"
                            />
                        </div>

                        @if($form->isMongodb())
                            <x-input
                                wire:model="form.auth_source"
                                label="{{ __('Authentication Database') }}"
                                placeholder="admin"
                                hint="{{ __('The database used to authenticate credentials') }}"
                                type="text"
                            />
                        @endif
                    @endif

                    @if($form->supportsDumpFlags())
                        <x-collapse class="mt-2" :open="!empty($form->dump_flags)">
                            <x-slot:heading>
                                <x-icon name="o-command-line" class="w-4 h-4" />
                                {{ __('Dump Command Configuration') }}
                            </x-slot:heading>
                            <x-slot:content class="space-y-3">
                                <x-input
                                    wire:model.live.debounce.300ms="form.dump_flags"
                                    placeholder="{{ __('e.g., --no-tablespaces --verbose') }}"
                                    :hint="__('Additional flags appended to the dump command')"
                                    :label="__('Extra Dump Flags')"
                                    type="text"
                                />

                                @php $dumpPreview = $form->getDumpCommandPreview() @endphp
                                @if($dumpPreview)
                                    <x-badge :value="__('Command preview')" class="badge-primary badge-soft "/>
                                    <div class="mockup-code text-xs">
                                        <pre data-prefix="$"><code>{{ $dumpPreview }}</code></pre>
                                    </div>
                                @endif
                            </x-slot:content>
                        </x-collapse>
                    @endif

                    <!-- Test Connection Button -->
                    @if($form->hasAgent())
                        <x-alert class="alert-info mt-2" icon="o-information-circle">
                            {{ __('Connection testing is not available for agent-managed servers. The agent will test connectivity when running backups.') }}
                        </x-alert>
                    @else
                        <div class="flex flex-wrap items-center gap-2 pt-2">
                            <x-button
                                class="{{ $form->connectionTestSuccess ? 'btn-success' : 'btn-outline btn-primary' }}"
                                type="button"
                                icon="{{ $form->connectionTestSuccess ? 'o-check-circle' : 'o-bolt' }}"
                                wire:click="testConnection"
                                :disabled="$form->testingConnection"
                                spinner="testConnection"
                            >
                                @if($form->testingConnection)
                                    {{ __('Testing...') }}
                                @elseif($form->connectionTestSuccess)
                                    {{ __('Connection Verified') }}
                                    @if(!empty($form->connectionTestDetails['ping_ms']))
                                        ({{ $form->connectionTestDetails['ping_ms'] }}ms)
                                    @endif
                                @else
                                    {{ __('Test Connection') }}
                                @endif
                            </x-button>

                            @if($form->connectionTestSuccess && !empty($form->connectionTestDetails['output']))
                                <x-button
                                    wire:click="$toggle('form.showConnectionDetails')"
                                    class="btn-ghost btn-sm"
                                    icon="{{ $form->showConnectionDetails ? 'o-eye-slash' : 'o-eye' }}"
                                    :label="$form->showConnectionDetails ? __('Hide Details') : __('Show Details')"
                                />
                            @endif
                        </div>

                        <!-- Connection Test Result -->
                        @if($form->connectionTestMessage && !$form->connectionTestSuccess)
                            <x-alert class="alert-error mt-2" icon="o-x-circle">
                                <div>
                                    <span class="font-bold">{{ __('Connection failed') }}</span>
                                    <p class="text-sm">{{ $form->connectionTestMessage }}</p>
                                </div>
                                <x-button
                                    :label="__('Troubleshooting Guide')"
                                    link="https://david-crty.github.io/databasement/user-guide/database-servers/#troubleshooting-connection-issues"
                                    external
                                    class="btn-ghost btn-sm mt-2"
                                    icon="o-arrow-top-right-on-square"
                                />
                            </x-alert>
                        @endif

                        @if($form->connectionTestSuccess && !empty($form->connectionTestDetails['ssh_tunnel']))
                            <x-alert class="alert-info mt-2" icon="o-server-stack">
                                {{ __('Connected via SSH tunnel through') }} {{ $form->connectionTestDetails['ssh_host'] }}
                            </x-alert>
                        @elseif($form->connectionTestSuccess && !empty($form->connectionTestDetails['sftp']))
                            <x-alert class="alert-info mt-2" icon="o-server-stack">
                                {{ __('Connected via SFTP through') }} {{ $form->connectionTestDetails['ssh_host'] }}
                            </x-alert>
                        @endif

                        @if($form->showConnectionDetails && !empty($form->connectionTestDetails['output']))
                            <div class="mockup-code text-sm max-h-64 overflow-auto mt-2 max-w-full w-full overflow-x-auto">
                                @foreach(explode("\n", trim($form->connectionTestDetails['output'])) as $line)
                                    <pre class="!whitespace-pre-wrap !break-all"><code>{{ $line }}</code></pre>
                                @endforeach
                            </div>
                        @endif
                    @endif
                @endif
            </div>
        </div>
    </div>

    <!-- Enable Backups Toggle (shown after successful connection test, agent assigned, or when editing) -->
    @if($form->connectionTestSuccess or $form->hasAgent() or $isEdit)
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-3 sm:p-8">
                <x-toggle
                    wire:model.live="form.backups_enabled"
                    label="{{ __('Enable Scheduled Backups') }}"
                    hint="{{ __('When disabled, this server will be skipped during scheduled backup runs') }}"
                    class="toggle-primary"
                />
            </div>
        </div>
    @endif

    <!-- Section 3: Backup Configuration (merged: What / Where / When / How long) -->
    @if(($form->connectionTestSuccess or $form->hasAgent() or $isEdit) && $form->backups_enabled)
        @php
            $showDatabaseSelection = !$form->isSqlite() && !$form->isRedis();
            $resolvedPathPreview = $form->getResolvedPathPreview();
            $summaryWhat = $form->getSummarySelectionText();
            $summaryVolume = $form->getSelectedVolumeLabel();
            $summarySchedule = $form->getSelectedScheduleLabel();
            $summaryHowLong = $form->getSummaryRetentionText();
            $summaryComplete = $form->isBackupConfigComplete();
        @endphp

        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-3 sm:p-8">
                <!-- Card header -->
                <div class="flex items-start gap-3 mb-6">
                    <span class="badge badge-primary badge-lg font-bold">3</span>
                    <div>
                        <h3 class="card-title text-lg leading-snug">{{ __('Backup Configuration') }}</h3>
                        <p class="text-xs text-base-content/60 mt-0.5">
                            {{ __('Define what, where, when, and how long to keep your backups.') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-6">
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
                                    :active="$form->database_selection_mode === DatabaseSelectionMode::All->value"
                                    icon="o-circle-stack"
                                    :label="__('All databases')"
                                    :hint="__('Back up every user database')"
                                    :value="DatabaseSelectionMode::All->value"
                                    wire:model.live="form.database_selection_mode"
                                />
                                <x-radio-card
                                    :active="$form->database_selection_mode === DatabaseSelectionMode::Selected->value"
                                    icon="o-check-badge"
                                    :label="__('Selected')"
                                    :hint="__('Pick specific databases')"
                                    :value="DatabaseSelectionMode::Selected->value"
                                    wire:model.live="form.database_selection_mode"
                                />
                                <x-radio-card
                                    :active="$form->database_selection_mode === DatabaseSelectionMode::Pattern->value"
                                    icon="bi.regex"
                                    :label="__('Pattern')"
                                    :hint="__('Filter by regex')"
                                    :value="DatabaseSelectionMode::Pattern->value"
                                    wire:model.live="form.database_selection_mode"
                                />
                            </x-radio-card-group>

                            {{-- All Databases sub-panel --}}
                            @if($form->database_selection_mode === DatabaseSelectionMode::All->value)
                                <div class="rounded-lg border border-base-300 bg-base-200/40 p-4 flex items-start gap-3">
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
                            @if($form->database_selection_mode === DatabaseSelectionMode::Selected->value)
                                <div class="rounded-lg border border-base-300 bg-base-200/40 p-4">
                                    @if($form->loadingDatabases)
                                        <div class="flex items-center gap-2 text-base-content/70">
                                            <x-loading class="loading-spinner loading-sm" />
                                            {{ __('Loading databases...') }}
                                        </div>
                                    @elseif(count($form->availableDatabases) > 0)
                                        <x-choices-offline
                                            wire:model="form.database_names"
                                            :label="__('Select Databases')"
                                            :options="$form->availableDatabases"
                                            :hint="__('Select one or more databases to backup')"
                                            searchable
                                        />
                                    @else
                                        <x-input
                                            wire:model.live.debounce.400ms="form.database_names_input"
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
                            @if($form->database_selection_mode === DatabaseSelectionMode::Pattern->value)
                                <div class="rounded-lg border border-base-300 bg-base-200/40 p-4 space-y-4">
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
                                                wire:model.live.debounce.300ms="form.database_include_pattern"
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

                                    @error('form.database_include_pattern')
                                        <x-alert class="alert-error" icon="o-x-circle">
                                            {{ $message }}
                                        </x-alert>
                                    @enderror

                                    {{-- Live preview --}}
                                    @if(count($form->availableDatabases) > 0)
                                        @php
                                            $hasPattern = $form->database_include_pattern !== '';
                                            $isValidPattern = !$hasPattern || \App\Models\DatabaseServer::isValidDatabasePattern($form->database_include_pattern);
                                            $filteredDbs = $hasPattern && $isValidPattern ? $form->getFilteredDatabases() : [];
                                        @endphp

                                        @if($hasPattern && !$isValidPattern)
                                            <x-alert class="alert-warning" icon="o-exclamation-triangle">
                                                {{ __('Invalid regular expression pattern.') }}
                                            </x-alert>
                                        @else
                                            <div class="rounded-lg border border-base-300 bg-base-100 overflow-hidden">
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
                                                        <div class="flex items-center gap-2.5 px-3 py-2 text-sm transition-opacity {{ $hasPattern && !$matched ? 'opacity-35' : '' }}">
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
                            wire:model.live="form.volume_id"
                            :label="__('Storage Volume')"
                            :options="$form->getVolumeOptions()"
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
                                wire:model.live.debounce.300ms="form.path"
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
                            wire:model.live="form.backup_schedule_id"
                            :label="__('Backup Schedule')"
                            :options="$form->getScheduleOptions()"
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
                                :active="$form->retention_policy === \App\Models\Backup::RETENTION_DAYS"
                                icon="o-calendar-days"
                                :label="__('Days')"
                                :hint="__('Keep the last N days')"
                                :value="\App\Models\Backup::RETENTION_DAYS"
                                wire:model.live="form.retention_policy"
                            />
                            <x-radio-card
                                :active="$form->retention_policy === \App\Models\Backup::RETENTION_GFS"
                                icon="o-square-3-stack-3d"
                                :label="__('GFS')"
                                :hint="__('Tiered retention')"
                                :value="\App\Models\Backup::RETENTION_GFS"
                                wire:model.live="form.retention_policy"
                            />
                            <x-radio-card
                                :active="$form->retention_policy === \App\Models\Backup::RETENTION_FOREVER"
                                icon="o-arrow-path-rounded-square"
                                :label="__('Forever')"
                                :hint="__('Keep everything')"
                                :value="\App\Models\Backup::RETENTION_FOREVER"
                                wire:model.live="form.retention_policy"
                            />
                        </x-radio-card-group>

                        {{-- Days sub-panel --}}
                        @if($form->retention_policy === \App\Models\Backup::RETENTION_DAYS)
                            <div class="rounded-lg border border-base-300 bg-base-200/40 p-4 space-y-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex items-center rounded-md border border-base-300 bg-base-100 overflow-hidden">
                                        <input
                                            type="number"
                                            wire:model.live.debounce.300ms="form.retention_days"
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
                                                wire:click="$set('form.retention_days', {{ $mark }})"
                                                class="text-xs tabular-nums font-mono transition-colors {{ (int) $form->retention_days === $mark ? 'font-bold text-primary' : 'text-base-content/40 hover:text-base-content/70' }}"
                                            >
                                                {{ $mark }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>

                                <p class="text-xs text-base-content/60 border-t border-base-200 pt-3 leading-relaxed">
                                    {{ trans_choice('{1} Snapshots older than :count day will be deleted automatically during the next cleanup run.|[2,*] Snapshots older than :count days will be deleted automatically during the next cleanup run.', (int) ($form->retention_days ?? 14), ['count' => (int) ($form->retention_days ?? 14)]) }}
                                </p>
                            </div>
                        @endif

                        {{-- GFS sub-panel --}}
                        @if($form->retention_policy === \App\Models\Backup::RETENTION_GFS)
                            <div class="rounded-lg border border-base-300 bg-base-200/40 p-4 space-y-4">
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
                                            wire:model.live.debounce.400ms="form.gfs_keep_daily"
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
                                            wire:model.live.debounce.400ms="form.gfs_keep_weekly"
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
                                            wire:model.live.debounce.400ms="form.gfs_keep_monthly"
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
                        @if($form->retention_policy === \App\Models\Backup::RETENTION_FOREVER)
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
                                        {{ __('Finish configuring the section above to see a summary of your backup plan.') }}
                                    </p>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Section 4: Notifications -->
    @if($form->connectionTestSuccess or $form->hasAgent() or $isEdit)
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-3 sm:p-8">
                <div class="flex items-center gap-3 mb-4">
                    <span class="badge badge-primary badge-lg font-bold">{{ $form->backups_enabled ? 4 : 3 }}</span>
                    <h3 class="card-title text-lg">{{ __('Notifications') }}</h3>
                </div>

                @php
                    $notificationChannels = $form->getNotificationChannels();
                    $hasChannels = $notificationChannels->isNotEmpty();
                    $isDisabled = $form->notification_trigger === 'none';
                    $triggerOptions = [
                        'all' => ['icon' => 'o-bell-alert', 'label' => __('All events'), 'hint' => __('Success & failure'), 'color' => 'info'],
                        'success' => ['icon' => 'o-check-circle', 'label' => __('Success only'), 'hint' => __('Completed backups'), 'color' => 'success'],
                        'failure' => ['icon' => 'o-exclamation-triangle', 'label' => __('Failure only'), 'hint' => __('Errors & timeouts'), 'color' => 'error'],
                        'none' => ['icon' => 'o-bell-slash', 'label' => __('Disabled'), 'hint' => __('No notifications'), 'color' => 'default'],
                    ];
                @endphp

                <div class="space-y-5">
                    <!-- Trigger selection -->
                    <div class="space-y-2">
                        <div>
                            <p class="text-sm font-semibold">{{ __('Notify me on') }}</p>
                            <p class="text-xs text-base-content/60">{{ __('When should this server send a notification?') }}</p>
                        </div>
                        <x-radio-card-group class="grid-cols-2 sm:grid-cols-4" :label="__('Notification trigger')">
                            @foreach($triggerOptions as $value => $opt)
                                <x-radio-card
                                    :active="$form->notification_trigger === $value"
                                    :color="$opt['color']"
                                    :icon="$opt['icon']"
                                    :label="$opt['label']"
                                    :hint="$opt['hint']"
                                    :value="$value"
                                    wire:model.live="form.notification_trigger"
                                />
                            @endforeach
                        </x-radio-card-group>
                    </div>

                    <!-- Channel selection (hidden when disabled) -->
                    @if(! $isDisabled)
                        @if(! $hasChannels)
                            <!-- Empty state: no channels exist at all -->
                            <div class="flex flex-col items-center justify-center gap-4 rounded-lg border-2 border-dashed border-base-300 bg-base-200/50 px-6 py-10 text-center">
                                <span class="inline-flex items-center justify-center rounded-full bg-base-200 p-3">
                                    <x-icon name="o-bell-alert" class="w-6 h-6 text-base-content/50" />
                                </span>
                                <div class="space-y-1">
                                    <p class="text-sm font-semibold">{{ __('No notification channels yet') }}</p>
                                    <p class="text-xs text-base-content/60 max-w-xs">
                                        {{ __('Add at least one channel (Email, Slack, Webhook…) to receive backup alerts.') }}
                                    </p>
                                </div>
                                <x-button
                                    icon="o-plus"
                                    class="btn-primary btn-sm"
                                    link="{{ route('configuration.index') }}#notification-channels"
                                    external
                                    :label="__('Create your first channel')"
                                />
                            </div>
                        @else
                            <!-- Channel selection mode -->
                            <div class="space-y-2">
                                <div>
                                    <p class="text-sm font-semibold">{{ __('Send to') }}</p>
                                    <p class="text-xs text-base-content/60">{{ __('Target one or all of your notification channels.') }}</p>
                                </div>
                                @php
                                    $modeOptions = [
                                        'all' => [
                                            'icon' => 'o-user-group',
                                            'label' => __('All channels'),
                                            'hint' => __(':count configured', ['count' => $notificationChannels->count()]),
                                        ],
                                        'selected' => [
                                            'icon' => 'o-adjustments-horizontal',
                                            'label' => __('Specific channels'),
                                            'hint' => __('Pick individual channels'),
                                        ],
                                    ];
                                @endphp
                                <x-radio-card-group class="grid-cols-1 sm:grid-cols-2" :label="__('Send to')">
                                    @foreach($modeOptions as $value => $opt)
                                        <x-radio-card
                                            :active="$form->notification_channel_selection === $value"
                                            :icon="$opt['icon']"
                                            :label="$opt['label']"
                                            :hint="$opt['hint']"
                                            :value="$value"
                                            horizontal
                                            wire:model.live="form.notification_channel_selection"
                                        />
                                    @endforeach
                                </x-radio-card-group>
                            </div>

                            <!-- Channel picker (when 'selected') -->
                            @if($form->notification_channel_selection === 'selected')
                                @php $hasChannelError = $errors->has('form.notification_channel_ids'); @endphp
                                <div class="space-y-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-semibold {{ $hasChannelError ? 'text-error' : '' }}">{{ __('Select channels') }}</p>
                                            <p class="text-xs {{ $hasChannelError ? 'text-error/80' : 'text-base-content/60' }}">
                                                {{ __(':selected of :total selected', ['selected' => count($form->notification_channel_ids), 'total' => $notificationChannels->count()]) }}
                                            </p>
                                        </div>
                                        @if(count($form->notification_channel_ids) > 0)
                                            <button
                                                type="button"
                                                wire:click="$set('form.notification_channel_ids', [])"
                                                class="text-xs text-base-content/60 hover:text-base-content hover:underline cursor-pointer"
                                            >
                                                {{ __('Clear all') }}
                                            </button>
                                        @endif
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                        @foreach($notificationChannels as $channel)
                                            @php $isSelected = in_array($channel->id, $form->notification_channel_ids, true); @endphp
                                            <button
                                                type="button"
                                                role="checkbox"
                                                aria-checked="{{ $isSelected ? 'true' : 'false' }}"
                                                wire:click="toggleNotificationChannel('{{ $channel->id }}')"
                                                wire:key="channel-card-{{ $channel->id }}"
                                                class="relative flex items-center gap-3 rounded-lg border-2 p-3 text-left transition-all cursor-pointer {{ $isSelected ? 'border-primary bg-primary/5 shadow-sm' : 'border-base-300 bg-base-100 hover:bg-base-200' }}"
                                            >
                                                <span class="shrink-0 rounded-md p-2 {{ $isSelected ? 'bg-primary/10 text-primary' : 'bg-base-200 text-base-content/60' }}">
                                                    <x-icon :name="$channel->type->icon()" class="w-5 h-5" />
                                                </span>
                                                <span class="flex-1 min-w-0">
                                                    <span class="block text-sm font-semibold truncate">{{ $channel->name }}</span>
                                                    <span class="block text-xs text-base-content/60 truncate">{{ $channel->type->label() }}</span>
                                                </span>
                                                <span class="shrink-0 w-5 h-5 rounded-md border-2 flex items-center justify-center {{ $isSelected ? 'border-primary bg-primary' : 'border-base-300' }}">
                                                    @if($isSelected)
                                                        <x-icon name="s-check" class="w-3.5 h-3.5 text-primary-content" />
                                                    @endif
                                                </span>
                                            </button>
                                        @endforeach
                                    </div>
                                    @if($hasChannelError)
                                        <x-alert class="alert-error" icon="o-x-circle">
                                            {{ __('Select at least one channel, or switch to “All channels”.') }}
                                        </x-alert>
                                    @endif
                                </div>
                            @endif
                        @endif
                    @endif

                    <!-- Live summary -->
                    @php
                        $channelCount = $form->notification_channel_selection === 'all'
                            ? $notificationChannels->count()
                            : count($form->notification_channel_ids);
                        $summaryHasChannels = $channelCount > 0;
                        $triggerLabels = [
                            'all' => __('all events'),
                            'success' => __('success events only'),
                            'failure' => __('failure events only'),
                        ];
                    @endphp
                    @if($isDisabled)
                        <div class="flex items-start gap-2.5 rounded-lg border border-base-300 bg-base-200 px-4 py-3">
                            <x-icon name="o-bell-slash" class="w-5 h-5 text-base-content/50 shrink-0 mt-0.5" />
                            <p class="text-sm text-base-content/70 leading-snug">
                                {{ __('Notifications are disabled for this server. No alerts will be sent.') }}
                            </p>
                        </div>
                    @elseif($hasChannels && $summaryHasChannels)
                        <div class="flex items-start gap-2.5 rounded-lg border border-success/30 bg-success/5 px-4 py-3">
                            <x-icon name="o-bell-alert" class="w-5 h-5 text-success shrink-0 mt-0.5" />
                            <p class="text-sm leading-snug">
                                {{ __('Notifications will be sent to') }}
                                <span class="font-semibold">{{ trans_choice('{1} :count channel|[2,*] :count channels', $channelCount, ['count' => $channelCount]) }}</span>
                                {{ __('on') }}
                                <span class="font-semibold">{{ $triggerLabels[$form->notification_trigger] ?? '' }}</span>.
                            </p>
                        </div>
                    @elseif($hasChannels)
                        <div class="flex items-start gap-2.5 rounded-lg border border-warning/30 bg-warning/5 px-4 py-3">
                            <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-warning shrink-0 mt-0.5" />
                            <p class="text-sm leading-snug">
                                <span class="font-semibold">{{ __('No channels selected.') }}</span>
                                {{ __('Pick at least one channel above, or switch to “All channels”.') }}
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Form Actions -->
    <div class="flex flex-col-reverse sm:flex-row items-center justify-end gap-3 pt-4">
        <x-button class="btn-ghost w-full sm:w-auto" link="{{ route($cancelRoute) }}" wire:navigate>
            {{ __('Cancel') }}
        </x-button>
        <x-button
            class="btn-primary w-full sm:w-auto"
            type="submit"
            icon="o-check"
            spinner="save"
        >
            {{ __($submitLabel) }}
        </x-button>
    </div>
</x-form>
