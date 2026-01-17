@props(['form', 'submitLabel' => 'Save', 'cancelRoute' => 'database-servers.index', 'isEdit' => false])

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
                    hint="{{ __('Notes for your team about this server\'s purpose') }}"
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
                <x-select
                    wire:model.live="form.database_type"
                    label="{{ __('Database Type') }}"
                    :options="$form->getDatabaseTypeOptions()"
                    hint="{{ __('Select your database engine') }}"
                />

                @if($form->isSqlite())
                    <!-- SQLite Path -->
                    <x-input
                        wire:model.blur="form.sqlite_path"
                        label="{{ __('Database File Path') }}"
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
                            min="1"
                            max="65535"
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
                            placeholder="{{ $isEdit ? __('Leave blank to keep current') : __('Database password') }}"
                            :required="!$isEdit"
                            autocomplete="off"
                        />
                    </div>
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
                    </x-alert>
                @endif

                @if($form->showConnectionDetails && !empty($form->connectionTestDetails['output']))
                    <div class="mockup-code text-sm max-h-64 overflow-auto mt-2 max-w-full w-full">
                        @foreach(explode("\n", trim($form->connectionTestDetails['output'])) as $line)
                            <pre><code>{{ $line }}</code></pre>
                        @endforeach
                    </div>
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
    @if(($form->connectionTestSuccess or $isEdit) && !$form->isSqlite() && $form->backups_enabled)
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <div class="flex items-center gap-3 mb-4">
                    <span class="badge badge-primary badge-lg font-bold">3</span>
                    <h3 class="card-title text-lg">{{ __('Database Selection') }}</h3>
                </div>

                <div class="space-y-4">
                    <div class="p-4 rounded-lg bg-base-200">
                        <x-checkbox
                            wire:model.live="form.backup_all_databases"
                            :label="$form->availableDatabases ? __('Backup all databases') . ' (' . count($form->availableDatabases) . ' ' . __('available') . ')' : __('Backup all databases')"
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
            </div>
        </div>
    @endif

    <!-- Section 4: Backup Configuration (only shown when backups enabled) -->
    @if(($form->connectionTestSuccess or $isEdit) && $form->backups_enabled)
        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="card-body">
                <div class="flex items-center gap-3 mb-4">
                    <span class="badge badge-primary badge-lg font-bold">{{ $form->isSqlite() ? '3' : '4' }}</span>
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
                                wire:click="$refresh"
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
                        placeholder="{{ __('e.g., production/mysql/') }}"
                        hint="{{ __('Optional subfolder path within the volume to organize backups.') }}"
                        type="text"
                        icon="o-folder"
                    />

                    <div class="grid gap-4 md:grid-cols-2">
                        <x-select
                            wire:model="form.recurrence"
                            label="{{ __('Backup Frequency') }}"
                            :options="$form->getRecurrenceOptions()"
                            required
                        />

                        <x-select
                            wire:model.live="form.retention_policy"
                            label="{{ __('Retention Policy') }}"
                            :options="$form->getRetentionPolicyOptions()"
                        />
                    </div>

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
                                        {{ __('Keeps recent backups for quick recovery while preserving older snapshots for archival. Default: 7 daily + 4 weekly + 12 monthly backups.') }}
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
