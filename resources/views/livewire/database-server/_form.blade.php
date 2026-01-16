@props(['form', 'submitLabel' => 'Save', 'cancelRoute' => 'database-servers.index', 'isEdit' => false])

@php
$databaseTypes = App\Enums\DatabaseType::toSelectOptions();

$recurrenceOptions = collect(App\Models\Backup::RECURRENCE_TYPES)->map(fn($type) => [
    'id' => $type,
    'name' => __(Str::ucfirst($type)),
])->toArray();

$retentionPolicyOptions = [
    ['id' => 'simple', 'name' => __('Simple (days-based)')],
    ['id' => 'gfs', 'name' => __('GFS (Grandfather-Father-Son)')],
];

$volumes = \App\Models\Volume::orderBy('name')->get()->map(fn($v) => [
    'id' => $v->id,
    'name' => "{$v->name} ({$v->type})",
])->toArray();
@endphp

<x-form wire:submit="save">
    <!-- Basic Information -->
    <div class="space-y-4">
        <h3 class="text-lg font-semibold">{{ __('Basic Information') }}</h3>

        <x-input
            wire:model="form.name"
            label="{{ __('Server Name') }}"
            placeholder="{{ __('e.g., Production MySQL Server') }}"
            type="text"
            required
        />

        <x-textarea
            wire:model="form.description"
            label="{{ __('Description') }}"
            placeholder="{{ __('Optional description for this server') }}"
            rows="3"
        />
    </div>

    <!-- Step 1: Connection Details -->
    <x-hr />

    <div class="space-y-4">
        <div class="flex items-center gap-2">
            <div class="flex items-center justify-center w-6 h-6 text-sm font-bold rounded-full {{ $form->connectionTestSuccess ? 'bg-success text-success-content' : 'bg-base-300 text-base-content' }}">
                @if($form->connectionTestSuccess)
                    <x-icon name="o-check" class="w-4 h-4" />
                @else
                    1
                @endif
            </div>
            <h3 class="text-lg font-semibold">{{ __('Connection Details') }}</h3>
        </div>

        <x-select
            wire:model.live="form.database_type"
            label="{{ __('Database Type') }}"
            :options="$databaseTypes"
        />

        @if($form->isSqlite())
            <!-- SQLite Path -->
            <x-input
                wire:model.blur="form.sqlite_path"
                label="{{ __('Database Path') }}"
                placeholder="{{ __('e.g., /var/data/database.sqlite') }}"
                hint="{{ __('Absolute path to the SQLite database file') }}"
                type="text"
                required
            />
        @else
            <!-- Client-server database connection fields -->
            <div class="grid gap-4 md:grid-cols-2">
                <x-input
                    wire:model.blur="form.host"
                    label="{{ __('Host') }}"
                    placeholder="{{ __('e.g., localhost or 192.168.1.100') }}"
                    type="text"
                    required
                />

                <x-input
                    wire:model.blur="form.port"
                    label="{{ __('Port') }}"
                    placeholder="{{ __('e.g., 3306') }}"
                    type="number"
                    required
                />
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <x-input
                    wire:model.blur="form.username"
                    label="{{ __('Username') }}"
                    placeholder="{{ __('Database username') }}"
                    type="text"
                    required
                    autocomplete="off"
                />

                <x-password
                    wire:model.blur="form.password"
                    label="{{ __('Password') }}"
                    placeholder="{{ $isEdit ? __('Leave blank to keep current password') : __('Database password') }}"
                    :required="!$isEdit"
                    autocomplete="off"
                />
            </div>
        @endif

        <!-- Test Connection Button -->
        <div class="pt-2">
            <x-button
                class="w-full {{ $form->connectionTestSuccess ? 'btn-success' : 'btn-outline' }}"
                type="button"
                icon="{{ $form->connectionTestSuccess ? 'o-check-circle' : 'o-arrow-path' }}"
                wire:click="testConnection"
                :disabled="$form->testingConnection"
                spinner="testConnection"
            >
                @if($form->testingConnection)
                    {{ __('Testing Connection...') }}
                @elseif($form->connectionTestSuccess)
                    {{ __('Connection Verified') }}
                @else
                    {{ __('Test Connection') }}
                @endif
            </x-button>
        </div>

        <!-- Connection Test Result -->
        @if($form->connectionTestMessage)
            <div class="mt-2">
                @if($form->connectionTestSuccess)
                    <x-alert class="alert-success" icon="o-check-circle">
                        <div class="flex flex-col gap-2">
                            <span class="font-semibold">
                                {{ __('Connection successful') }}
                                @if(!empty($form->connectionTestDetails) && isset($form->connectionTestDetails['ping_ms']))
                                    ({{ __('Ping: ') }}{{ $form->connectionTestDetails['ping_ms'] }}ms)
                                @endif
                            </span>
                        </div>
                    </x-alert>
                    @if(!empty($form->connectionTestDetails) && isset($form->connectionTestDetails['output']))
                        <div class="mockup-code text-sm max-h-64 overflow-auto mt-2 max-w-full w-full">
                            @foreach(explode("\n", trim($form->connectionTestDetails['output'])) as $line)
                                <pre class="!whitespace-pre-wrap !break-all"><code>{{ $line }}</code></pre>
                            @endforeach
                        </div>
                    @endif
                @else
                    <x-alert class="alert-error" icon="o-x-circle">
                        {{ $form->connectionTestMessage }}
                    </x-alert>
                @endif
            </div>
        @endif
    </div>

    <!-- Enable Backups Toggle (shown after successful connection test or when editing) -->
    @if($form->connectionTestSuccess or $isEdit)
        <x-hr />

        <div class="p-4 rounded-lg bg-base-200">
            <x-checkbox
                wire:model.live="form.backups_enabled"
                label="{{ __('Enable scheduled backups') }}"
                hint="{{ __('When disabled, this server will be skipped during scheduled backup runs and backup configuration is not required.') }}"
            />
        </div>
    @endif

    <!-- Step 2: Database Selection (only shown after successful connection, not for SQLite, and when backups enabled) -->
    @if(($form->connectionTestSuccess or $isEdit) && !$form->isSqlite() && $form->backups_enabled)
        <x-hr />

        <div class="space-y-4">
            <div class="flex items-center gap-2">
                <div class="flex items-center justify-center w-6 h-6 text-sm font-bold rounded-full bg-base-300 text-base-content">
                    2
                </div>
                <h3 class="text-lg font-semibold">{{ __('Database Selection') }}</h3>
            </div>

            <div class="p-4 rounded-lg bg-base-200">
                <x-checkbox
                    wire:model.live="form.backup_all_databases"
                    :label="$form->availableDatabases ? __('Backup all databases') . ' (' . count($form->availableDatabases) . ' available)' : __('Backup all databases')"
                    hint="{{ __('All user databases will be backed up. System databases are automatically excluded.') }}"
                />
            </div>

            @if(!$form->backup_all_databases)
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
        </div>
    @endif

    <!-- Backup Configuration (Step 2 for SQLite, Step 3 for others) - only shown when backups enabled -->
    @if(($form->connectionTestSuccess or $isEdit) && $form->backups_enabled)
        <x-hr />

        <div class="space-y-4">
            <div class="flex items-center gap-2">
                <div class="flex items-center justify-center w-6 h-6 text-sm font-bold rounded-full bg-base-300 text-base-content">
                    {{ $form->isSqlite() ? '2' : '3' }}
                </div>
                <h3 class="text-lg font-semibold">{{ __('Backup Configuration') }}</h3>
            </div>

            <x-select
                wire:model="form.volume_id"
                label="{{ __('Storage Volume') }}"
                :options="$volumes"
                placeholder="{{ __('Select a volume') }}"
                placeholder-value=""
                required
            />

            <x-input
                wire:model="form.path"
                label="{{ __('Path (optional)') }}"
                placeholder="{{ __('e.g., production/mysql/') }}"
                hint="{{ __('Optional subfolder path within the volume to organize backups.') }}"
                type="text"
            />

            <x-select
                wire:model="form.recurrence"
                label="{{ __('Backup Frequency') }}"
                :options="$recurrenceOptions"
                required
            />

            <x-select
                wire:model.live="form.retention_policy"
                label="{{ __('Retention Policy') }}"
                :options="$retentionPolicyOptions"
                hint="{{ __('Simple: delete snapshots older than X days. GFS: keep daily, weekly, and monthly snapshots.') }}"
            />

            @if($form->retention_policy === 'simple')
                <x-input
                    wire:model="form.retention_days"
                    label="{{ __('Retention Period (days)') }}"
                    placeholder="{{ __('e.g., 30') }}"
                    hint="{{ __('Snapshots older than this will be automatically deleted. Leave empty to keep all snapshots.') }}"
                    type="number"
                    min="1"
                    max="365"
                />
            @else
                <div class="p-4 rounded-lg bg-base-200 space-y-4">
                    <div class="space-y-2">
                        <p class="text-sm font-medium">{{ __('Grandfather-Father-Son (GFS) Retention') }}</p>
                        <p class="text-sm text-base-content/70">
                            {{ __('This tiered approach keeps recent backups for quick recovery while preserving older snapshots for long-term archival.') }}
                        </p>
                        <p class="text-sm text-base-content/70">
                            {{ __('With default values (7 daily, 4 weekly, 12 monthly), you\'ll keep: the last 7 days of backups, plus 1 backup per week for the past month, plus 1 backup per month for the past year.') }}
                        </p>
                    </div>
                    <div class="grid gap-4 md:grid-cols-3">
                        <x-input
                            wire:model="form.keep_daily"
                            label="{{ __('Daily') }}"
                            placeholder="{{ __('e.g., 7') }}"
                            hint="{{ __('Keep last N daily snapshots') }}"
                            type="number"
                            min="1"
                            max="90"
                        />
                        <x-input
                            wire:model="form.keep_weekly"
                            label="{{ __('Weekly') }}"
                            placeholder="{{ __('e.g., 4') }}"
                            hint="{{ __('Keep 1 per week for N weeks') }}"
                            type="number"
                            min="1"
                            max="52"
                        />
                        <x-input
                            wire:model="form.keep_monthly"
                            label="{{ __('Monthly') }}"
                            placeholder="{{ __('e.g., 12') }}"
                            hint="{{ __('Keep 1 per month for N months') }}"
                            type="number"
                            min="1"
                            max="24"
                        />
                    </div>
                    <p class="text-xs text-base-content/50">
                        {{ __('Leave any tier empty to disable it. Snapshots matching multiple tiers are counted only once.') }}
                    </p>
                </div>
            @endif
        </div>
    @endif

    <!-- Submit Button -->
    <div class="flex items-center justify-end gap-3 pt-4">
        <x-button class="btn-ghost" link="{{ route($cancelRoute) }}" wire:navigate>
            {{ __('Cancel') }}
        </x-button>
        <x-button
            class="btn-primary"
            type="submit"
        >
            {{ __($submitLabel) }}
        </x-button>
    </div>
</x-form>
