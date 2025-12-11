@props(['form', 'submitLabel' => 'Save', 'cancelRoute' => 'database-servers.index', 'isEdit' => false])

@php
$databaseTypes = [
    ['id' => 'mysql', 'name' => 'MySQL'],
    ['id' => 'mariadb', 'name' => 'MariaDB'],
    ['id' => 'postgresql', 'name' => 'PostgreSQL'],
    ['id' => 'sqlite', 'name' => 'SQLite'],
];

$recurrenceOptions = collect(App\Models\Backup::RECURRENCE_TYPES)->map(fn($type) => [
    'id' => $type,
    'name' => __(Str::ucfirst($type)),
])->toArray();

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

        <!-- Connection Test Result (only show errors) -->
        @if($form->connectionTestMessage && !$form->connectionTestSuccess)
            <div class="mt-2">
                <x-alert class="alert-error" icon="o-x-circle">
                    {{ $form->connectionTestMessage }}
                </x-alert>
            </div>
        @endif
    </div>

    <!-- Step 2: Database Selection (only shown after successful connection) -->
    @if($form->connectionTestSuccess)
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
                    label="{{ __('Backup all databases') }}"
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
                    <x-select
                        wire:model="form.database_name"
                        label="{{ __('Select Database') }}"
                        :options="$form->availableDatabases"
                        placeholder="{{ __('Choose a database to backup') }}"
                        placeholder-value=""
                        required
                    />
                @else
                    <x-input
                        wire:model="form.database_name"
                        label="{{ __('Database Name') }}"
                        placeholder="{{ __('e.g., my_database') }}"
                        hint="{{ __('Enter the database name manually') }}"
                        type="text"
                        required
                    />
                @endif
            @endif
        </div>

        <!-- Backup Configuration -->
        <x-hr />

        <div class="space-y-4">
            <div class="flex items-center gap-2">
                <div class="flex items-center justify-center w-6 h-6 text-sm font-bold rounded-full bg-base-300 text-base-content">
                    3
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

            <x-select
                wire:model="form.recurrence"
                label="{{ __('Backup Frequency') }}"
                :options="$recurrenceOptions"
                required
            />
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
            :disabled="!$form->connectionTestSuccess"
        >
            {{ __($submitLabel) }}
        </x-button>
    </div>
</x-form>
