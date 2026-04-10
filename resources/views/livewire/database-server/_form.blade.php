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
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                        @foreach(DatabaseType::cases() as $dbType)
                            @php
                                $isSelected = $form->database_type === $dbType->value;
                                $buttonClass = $isSelected ? 'btn-primary' : 'btn-outline';
                            @endphp
                            <button
                                type="button"
                                wire:click="$set('form.database_type', '{{ $dbType->value }}')"
                                class="btn justify-start gap-2 h-auto py-3 {{ $buttonClass }}"
                            >
                                <x-database-type-icon :type="$dbType" class="w-5 h-5" />
                                <span>{{ $dbType->label() }}</span>
                            </button>
                        @endforeach
                    </div>
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

    <!-- Section 3: Database Selection (only shown after successful connection, agent assigned, not for SQLite, and when backups enabled) -->
    @if(($form->connectionTestSuccess or $form->hasAgent() or $isEdit) && !$form->isSqlite() && !$form->isRedis() && $form->backups_enabled)
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-3 sm:p-8">
                <div class="flex items-center gap-3 mb-4">
                    <span class="badge badge-primary badge-lg font-bold">3</span>
                    <h3 class="card-title text-lg">{{ __('Database Selection') }}</h3>
                </div>

                <div class="space-y-4">
                    <!-- Segmented Control -->
                    @php
                        $modes = [
                            DatabaseSelectionMode::All->value => ['icon' => 'o-circle-stack', 'label' => __('All Databases'), 'hint' => __('Backup everything')],
                            DatabaseSelectionMode::Selected->value => ['icon' => 'o-check-badge', 'label' => __('Selected'), 'hint' => __('Pick specific ones')],
                            DatabaseSelectionMode::Pattern->value => ['icon' => null, 'label' => __('Pattern'), 'hint' => __('Match by regex')],
                        ];
                    @endphp
                    <div class="grid grid-cols-3 gap-2 rounded-xl bg-base-200 p-2">
                        @foreach($modes as $mode => $opt)
                            @php $isActive = $form->database_selection_mode === $mode; @endphp
                            <button
                                type="button"
                                wire:click="$set('form.database_selection_mode', '{{ $mode }}')"
                                class="flex flex-col items-center gap-1 rounded-lg px-3 py-3 text-center transition-all cursor-pointer {{ $isActive ? 'bg-base-100 shadow-sm ring-1 ring-base-300' : 'hover:bg-base-100/50' }}"
                            >
                                @if($opt['icon'])
                                    <x-icon :name="$opt['icon']" class="w-5 h-5 {{ $isActive ? 'text-base-content' : 'text-base-content/50' }}" />
                                @else
                                    <x-icon-regex class="w-5 h-5 {{ $isActive ? 'text-base-content' : 'text-base-content/50' }}" />
                                @endif
                                <span class="text-sm font-semibold {{ $isActive ? 'text-base-content' : 'text-base-content/70' }}">{{ $opt['label'] }}</span>
                                <span class="text-xs {{ $isActive ? 'text-base-content/60' : 'text-base-content/40' }}">{{ $opt['hint'] }}</span>
                            </button>
                        @endforeach
                    </div>

                    <!-- All Databases Panel -->
                    @if($form->database_selection_mode === DatabaseSelectionMode::All->value)
                        <x-alert class="alert-info" icon="o-information-circle">
                            {{ __('All user databases will be backed up. System databases are automatically excluded.') }}
                            @if(count($form->availableDatabases) > 0)
                                <span class="font-semibold">({{ count($form->availableDatabases) }} {{ __('available') }})</span>
                            @endif
                        </x-alert>
                    @endif

                    <!-- Selected Databases Panel -->
                    @if($form->database_selection_mode === DatabaseSelectionMode::Selected->value)
                        @if($form->loadingDatabases)
                            <div class="flex items-center gap-2 text-base-content/70">
                                <x-loading class="loading-spinner loading-sm" />
                                {{ __('Loading databases...') }}
                            </div>
                        @elseif(count($form->availableDatabases) > 0)
                            <x-choices-offline
                                wire:model="form.database_names"
                                label="{{ __('Select Databases') }}"
                                :options="$form->availableDatabases"
                                hint="{{ __('Select one or more databases to backup') }}"
                                searchable
                            />
                        @else
                            <x-input
                                wire:model="form.database_names_input"
                                label="{{ __('Database Names') }}"
                                placeholder="{{ __('e.g., db1, db2, db3') }}"
                                hint="{{ __('Enter database names separated by commas') }}"
                                type="text"
                                required
                            />
                        @endif
                    @endif

                    <!-- Pattern Panel -->
                    @if($form->database_selection_mode === DatabaseSelectionMode::Pattern->value)
                        <div class="space-y-3">
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="text-xs font-medium text-base-content/70">{{ __('Include Pattern') }}</label>
                                    <span class="font-mono text-[10px] text-base-content/40">regex · case-insensitive</span>
                                </div>
                                <div class="flex items-center gap-0">
                                    <span class="bg-base-200 border border-r-0 border-base-300 rounded-l-lg px-3 py-2 text-base-content/50 font-mono text-sm">/</span>
                                    <input
                                        type="text"
                                        wire:model.live.debounce.300ms="form.database_include_pattern"
                                        class="input input-bordered rounded-none flex-1 font-mono text-sm"
                                        placeholder="{{ __('e.g., ^prod_ or ^(?!test_)') }}"
                                    />
                                    <span class="bg-base-200 border border-l-0 border-base-300 rounded-r-lg px-3 py-2 text-base-content/50 font-mono text-sm">/i</span>
                                </div>
                                <div class="mt-2 text-xs text-base-content/50 space-y-1">
                                    <div class="font-semibold">{{ __('Examples:') }}</div>
                                    <div class="flex items-baseline gap-2">
                                        <code class="bg-base-200 px-1.5 py-0.5 rounded font-mono shrink-0">^prod_</code>
                                        <span>{{ __('matches databases starting with prod_') }}</span>
                                    </div>
                                    <div class="flex items-baseline gap-2">
                                        <code class="bg-base-200 px-1.5 py-0.5 rounded font-mono shrink-0">^(?!test_)</code>
                                        <span>{{ __('excludes databases starting with test_') }}</span>
                                    </div>
                                    <div class="flex items-baseline gap-2">
                                        <code class="bg-base-200 px-1.5 py-0.5 rounded font-mono shrink-0">^(?!.*preprod)</code>
                                        <span>{{ __('excludes databases containing preprod') }}</span>
                                    </div>
                                </div>
                            </div>

                            @error('form.database_include_pattern')
                                <x-alert class="alert-error" icon="o-x-circle">
                                    {{ $message }}
                                </x-alert>
                            @enderror

                            <!-- Live Preview -->
                            @if(count($form->availableDatabases) > 0 && $form->database_include_pattern !== '')
                                @php
                                    $filteredDbs = $form->getFilteredDatabases();
                                    $isValidPattern = \App\Models\DatabaseServer::isValidDatabasePattern($form->database_include_pattern);
                                @endphp

                                @if(!$isValidPattern)
                                    <x-alert class="alert-warning" icon="o-exclamation-triangle">
                                        {{ __('Invalid regular expression pattern.') }}
                                    </x-alert>
                                @else
                                    <div class="border border-base-300 rounded-lg overflow-hidden">
                                        <div class="bg-base-200 px-3 py-2 text-sm font-semibold border-b border-base-300">
                                            {{ __('Preview') }} — {{ count($filteredDbs) }}/{{ count($form->availableDatabases) }} {{ __('databases matched') }}
                                        </div>
                                        <div class="max-h-48 overflow-y-auto divide-y divide-base-200">
                                            @foreach($form->availableDatabases as $db)
                                                @php $matched = in_array($db['name'], $filteredDbs); @endphp
                                                <div class="flex items-center gap-2 px-3 py-1.5 text-sm {{ $matched ? '' : 'opacity-40' }}">
                                                    @if($matched)
                                                        <x-icon name="o-check-circle" class="w-4 h-4 text-success" />
                                                    @else
                                                        <x-icon name="o-minus-circle" class="w-4 h-4" />
                                                    @endif
                                                    <span class="font-mono">{{ $db['name'] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @elseif(empty($form->availableDatabases))
                                <x-alert class="alert-{{ $form->hasAgent() ? 'info' : 'warning' }}" icon="{{ $form->hasAgent() ? 'o-information-circle' : 'o-exclamation-triangle' }}">
                                    {{ $form->hasAgent() ? __('Pattern preview is not available for agent-managed servers.') : __('Test connection to see pattern preview.') }}
                                </x-alert>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Section 4: Backup Configuration (only shown when backups enabled) -->
    @if(($form->connectionTestSuccess or $form->hasAgent() or $isEdit) && $form->backups_enabled)
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-3 sm:p-8">
                <div class="flex items-center gap-3 mb-4">
                    <span class="badge badge-primary badge-lg font-bold">{{ ($form->isSqlite() || $form->isRedis()) ? '3' : '4' }}</span>
                    <h3 class="card-title text-lg">{{ __('Backup Configuration') }}</h3>
                </div>

                <div class="space-y-4">
                    <x-select
                        wire:model="form.volume_id"
                        label="{{ __('Storage Volume') }}"
                        :options="$form->getVolumeOptions()"
                        placeholder="{{ __('Select a storage volume') }}"
                        placeholder-value=""
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

                    <x-input
                        wire:model="form.path"
                        label="{{ __('Subfolder Path') }}"
                        placeholder="{{ __('e.g., backups/{year}/{month}/{day}') }}"
                        hint="{{ __('Optional path to organize backups. Supports {year}, {month}, {day} variables (e.g., backups/{year}/{month} → backups/2026/02).') }}"
                        type="text"
                        icon="o-folder"
                    />

                    <x-select
                        wire:model="form.backup_schedule_id"
                        label="{{ __('Backup Schedule') }}"
                        :options="$form->getScheduleOptions()"
                        placeholder="{{ __('Select a schedule') }}"
                        placeholder-value=""
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
                                icon="o-plus"
                                class="btn-ghost join-item"
                                tooltip-bottom="{{ __('Manage schedules') }}"
                                external
                            />
                        </x-slot:append>
                    </x-select>

                    <x-select
                        wire:model.live="form.retention_policy"
                        label="{{ __('Retention Policy') }}"
                        :options="$form->getRetentionPolicyOptions()"
                    />

                    @if($form->retention_policy === 'days')
                        <x-input
                            wire:model="form.retention_days"
                            label="{{ __('Retention Period (days)') }}"
                            placeholder="{{ __('e.g., 30') }}"
                            hint="{{ __('Snapshots older than this will be automatically deleted.') }}"
                            type="number"
                            min="1"
                            max="365"
                            required
                        />
                    @elseif($form->retention_policy === 'gfs')
                        <div class="p-4 rounded-lg bg-base-200 space-y-4">
                            <div class="flex items-start gap-3">
                                <x-icon name="o-information-circle" class="w-5 h-5 text-info shrink-0 mt-0.5" />
                                <div>
                                    <p class="text-sm font-medium">{{ __('Grandfather-Father-Son (GFS) Retention') }}</p>
                                    <p class="text-sm text-base-content/70 mt-1">
                                        {{ __('Keeps recent backups for quick recovery while preserving older snapshots for archival. Retention is applied per database. Default: 7 daily + 4 weekly + 12 monthly backups.') }}
                                    </p>
                                </div>
                            </div>

                            <x-button
                                label="{{ __('View GFS Documentation') }}"
                                link="https://david-crty.github.io/databasement/user-guide/backups/#retention-policies"
                                external
                                class="btn-ghost btn-sm"
                                icon="o-arrow-top-right-on-square"
                            />

                            <div class="grid gap-4 md:grid-cols-3">
                                <x-input
                                    wire:model="form.gfs_keep_daily"
                                    label="{{ __('Daily') }}"
                                    placeholder="{{ __('e.g., 7') }}"
                                    hint="{{ __('Last N days') }}"
                                    type="number"
                                    min="0"
                                    max="90"
                                />
                                <x-input
                                    wire:model="form.gfs_keep_weekly"
                                    label="{{ __('Weekly') }}"
                                    placeholder="{{ __('e.g., 4') }}"
                                    hint="{{ __('1/week for N weeks') }}"
                                    type="number"
                                    min="0"
                                    max="52"
                                />
                                <x-input
                                    wire:model="form.gfs_keep_monthly"
                                    label="{{ __('Monthly') }}"
                                    placeholder="{{ __('e.g., 12') }}"
                                    hint="{{ __('1/month for N months') }}"
                                    type="number"
                                    min="0"
                                    max="24"
                                />
                            </div>

                            <p class="text-xs text-base-content/50">
                                {{ __('Leave any tier at 0 to disable it. Snapshots matching multiple tiers are counted only once.') }}
                            </p>
                        </div>
                    @else
                        <x-alert class="alert-warning" icon="o-exclamation-triangle">
                            {{ __('All snapshots will be kept indefinitely. Make sure you have enough storage space or manually delete old snapshots.') }}
                        </x-alert>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Section 5: Notifications -->
    @if($form->connectionTestSuccess or $form->hasAgent() or $isEdit)
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body p-3 sm:p-8">
                <div class="flex items-center gap-3 mb-4">
                    @php
                        $notifSection = ($form->isSqlite() || $form->isRedis()) ? ($form->backups_enabled ? 4 : 3) : ($form->backups_enabled ? 5 : 4);
                    @endphp
                    <span class="badge badge-primary badge-lg font-bold">{{ $notifSection }}</span>
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
                        'none' => ['icon' => 'o-bell-slash', 'label' => __('Disabled'), 'hint' => __('No notifications'), 'color' => 'muted'],
                    ];
                @endphp

                <div class="space-y-5">
                    <!-- Trigger selection -->
                    <div class="space-y-2">
                        <div>
                            <p class="text-sm font-semibold">{{ __('Notify me on') }}</p>
                            <p class="text-xs text-base-content/60">{{ __('When should this server send a notification?') }}</p>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3" role="radiogroup" aria-label="{{ __('Notification trigger') }}">
                            @foreach($triggerOptions as $value => $opt)
                                @php
                                    $isActive = $form->notification_trigger === $value;
                                    $activeClasses = match ($opt['color']) {
                                        'info' => 'border-info bg-info/5',
                                        'success' => 'border-success bg-success/5',
                                        'error' => 'border-error bg-error/5',
                                        default => 'border-base-content/30 bg-base-200',
                                    };
                                    $iconClasses = $isActive ? match ($opt['color']) {
                                        'info' => 'bg-info/10 text-info',
                                        'success' => 'bg-success/10 text-success',
                                        'error' => 'bg-error/10 text-error',
                                        default => 'bg-base-300 text-base-content/60',
                                    } : 'bg-base-200 text-base-content/60';
                                    $checkClasses = match ($opt['color']) {
                                        'info' => 'text-info',
                                        'success' => 'text-success',
                                        'error' => 'text-error',
                                        default => 'text-base-content/60',
                                    };
                                @endphp
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked="{{ $isActive ? 'true' : 'false' }}"
                                    wire:click="$set('form.notification_trigger', '{{ $value }}')"
                                    class="relative flex flex-col items-start gap-2.5 rounded-lg border-2 p-4 text-left transition-all cursor-pointer {{ $isActive ? $activeClasses.' shadow-sm' : 'border-base-300 bg-base-100 hover:bg-base-200' }}"
                                >
                                    @if($isActive)
                                        <x-icon name="s-check-circle" class="absolute top-2.5 right-2.5 w-4 h-4 {{ $checkClasses }}" />
                                    @endif
                                    <span class="rounded-md p-1.5 {{ $iconClasses }}">
                                        <x-icon :name="$opt['icon']" class="w-5 h-5" />
                                    </span>
                                    <span class="block">
                                        <span class="block text-sm font-semibold leading-tight">{{ $opt['label'] }}</span>
                                        <span class="block text-xs text-base-content/60 leading-snug mt-0.5">{{ $opt['hint'] }}</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
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
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
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
                                    @foreach($modeOptions as $value => $opt)
                                        @php $isActive = $form->notification_channel_selection === $value; @endphp
                                        <button
                                            type="button"
                                            wire:click="$set('form.notification_channel_selection', '{{ $value }}')"
                                            class="flex items-center gap-3 rounded-lg border-2 px-4 py-3 text-left transition-all cursor-pointer {{ $isActive ? 'border-primary bg-primary/5 shadow-sm' : 'border-base-300 bg-base-100 hover:bg-base-200' }}"
                                        >
                                            <x-icon :name="$opt['icon']" class="w-5 h-5 shrink-0 {{ $isActive ? 'text-primary' : 'text-base-content/60' }}" />
                                            <span class="flex-1 min-w-0">
                                                <span class="block text-sm font-semibold">{{ $opt['label'] }}</span>
                                                <span class="block text-xs text-base-content/60 mt-0.5">{{ $opt['hint'] }}</span>
                                            </span>
                                            @if($isActive)
                                                <x-icon name="s-check-circle" class="w-5 h-5 text-primary shrink-0" />
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
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
