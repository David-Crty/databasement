@props(['form', 'submitLabel' => 'Save', 'cancelRoute' => 'database-servers.index', 'isEdit' => false])

@php
$databaseTypes = [
    ['id' => 'mysql', 'name' => 'MySQL'],
    ['id' => 'mariadb', 'name' => 'MariaDB'],
    ['id' => 'postgresql', 'name' => 'PostgreSQL'],
    ['id' => 'sqlite', 'name' => 'SQLite'],
];

$recurrenceOptions = [
    ['id' => 'daily', 'name' => __('Daily')],
    ['id' => 'weekly', 'name' => __('Weekly')],
];

$volumes = \App\Models\Volume::orderBy('name')->get()->map(fn($v) => [
    'id' => $v->id,
    'name' => "{$v->name} ({$v->type})",
])->toArray();
@endphp

<form wire:submit="save" class="space-y-6">
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

    <!-- Connection Details -->
    <x-hr />

    <div class="space-y-4">
        <h3 class="text-lg font-semibold">{{ __('Connection Details') }}</h3>

        <x-select
            wire:model="form.database_type"
            label="{{ __('Database Type') }}"
            :options="$databaseTypes"
        />

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
                required
            />
        </div>

        <x-input
            wire:model="form.database_name"
            label="{{ __('Database Name') }}"
            type="text"
        />
    </div>

    <div class="space-y-4">
        <div class="grid gap-4 md:grid-cols-2">
            <x-input
                wire:model="form.username"
                label="{{ __('Username') }}"
                placeholder="{{ __('Database username') }}"
                type="text"
                required
                autocomplete="off"
            />

            <x-password
                wire:model="form.password"
                label="{{ __('Password') }}"
                placeholder="{{ $isEdit ? __('Leave blank to keep current password') : __('Database password') }}"
                :required="!$isEdit"
                autocomplete="off"
            />
        </div>

        <!-- Test Connection Button -->
        <div class="pt-2">
            <x-button
                class="w-full btn-outline"
                type="button"
                icon="o-arrow-path"
                wire:click="testConnection"
                :disabled="$form->testingConnection"
                spinner="testConnection"
            >
                @if($form->testingConnection)
                    {{ __('Testing Connection...') }}
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
                        {{ $form->connectionTestMessage }}
                    </x-alert>
                @else
                    <x-alert class="alert-error" icon="o-x-circle">
                        {{ $form->connectionTestMessage }}
                    </x-alert>
                @endif
            </div>
        @endif
    </div>

    <!-- Backup Configuration -->
    <x-hr />

    <div class="space-y-4">
        <h3 class="text-lg font-semibold">{{ __('Backup Configuration') }}</h3>

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

    <!-- Submit Button -->
    <div class="flex items-center justify-end gap-3 pt-4">
        <x-button class="btn-ghost" link="{{ route($cancelRoute) }}" wire:navigate>
            {{ __('Cancel') }}
        </x-button>
        <x-button class="btn-primary" type="submit">
            {{ __($submitLabel) }}
        </x-button>
    </div>
</form>
