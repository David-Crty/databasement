@props(['form', 'submitLabel' => 'Save', 'cancelRoute' => 'database-servers.index', 'isEdit' => false])

@php
use App\Enums\DatabaseType;
@endphp

<x-form wire:submit="save" class="space-y-6">
    <!-- Section 1: Basic Information -->
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body">
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
            </div>
        </div>
    </div>

    <!-- Section 2: Connection Details -->
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="card-body">
            <div class="flex items-center gap-3 mb-4">
                <span class="badge badge-primary badge-lg font-bold">2</span>
                <h3 class="card-title text-lg">{{ __('Connection Details') }}</h3>
            </div>

            <div class="space-y-4">
                <!-- Database Type Selection -->
                <div>
                    <label class="label label-text font-semibold mb-2">{{ __('Database Type') }}</label>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
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

                    <!-- Test Connection Button -->
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
                                label="{{ __('Troubleshooting Guide') }}"
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
            </div>
        </div>
    </div>

    <!-- Enable Backups Toggle (shown after successful connection test or when editing) -->
    @if($form->connectionTestSuccess or $isEdit)
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <x-toggle
                    wire:model.live="form.backups_enabled"
                    label="{{ __('Enable Scheduled Backups') }}"
                    hint="{{ __('When disabled, this server will be skipped during scheduled backup runs') }}"
                    class="toggle-primary"
                />
            </div>
        </div>
    @endif

    <!-- Section 3: Database Selection (only shown after successful connection, not for SQLite, and when backups enabled) -->
    @if(($form->connectionTestSuccess or $isEdit) && !$form->isSqlite() && !$form->isRedis() && $form->backups_enabled)
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <div class="flex items-center gap-3 mb-4">
                    <span class="badge badge-primary badge-lg font-bold">3</span>
                    <h3 class="card-title text-lg">{{ __('Database Selection') }}</h3>
                </div>

                <div class="space-y-4">
                    <!-- Segmented Control -->
                    @php
                        $modes = [
                            'all' => ['icon' => 'o-circle-stack', 'label' => __('All Databases'), 'hint' => __('Backup everything')],
                            'selected' => ['icon' => 'o-check-badge', 'label' => __('Selected'), 'hint' => __('Pick specific ones')],
                            'pattern' => ['icon' => null, 'label' => __('Pattern'), 'hint' => __('Match by regex')],
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
                    @if($form->database_selection_mode === 'all')
                        <x-alert class="alert-info" icon="o-information-circle">
                            {{ __('All user databases will be backed up. System databases are automatically excluded.') }}
                            @if(count($form->availableDatabases) > 0)
                                <span class="font-semibold">({{ count($form->availableDatabases) }} {{ __('available') }})</span>
                            @endif
                        </x-alert>
                    @endif

                    <!-- Selected Databases Panel -->
                    @if($form->database_selection_mode === 'selected')
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
                    @if($form->database_selection_mode === 'pattern')
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
                                <x-alert class="alert-warning" icon="o-exclamation-triangle">
                                    {{ __('Test connection to see pattern preview.') }}
                                </x-alert>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- Section 4: Backup Configuration (only shown when backups enabled) -->
    @if(($form->connectionTestSuccess or $isEdit) && $form->backups_enabled)
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
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
