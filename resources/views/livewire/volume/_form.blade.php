@props(['form', 'submitLabel' => 'Save', 'cancelRoute' => 'volumes.index'])

@php
$storageTypes = [
    ['id' => 'local', 'name' => 'Local Storage'],
    ['id' => 's3', 'name' => 'Amazon S3'],
];
@endphp

<form wire:submit="save" class="space-y-6">
    <!-- Basic Information -->
    <div class="space-y-4">
        <h3 class="text-lg font-semibold">{{ __('Basic Information') }}</h3>

        <x-input
            wire:model="form.name"
            label="{{ __('Volume Name') }}"
            placeholder="{{ __('e.g., Production S3 Bucket') }}"
            type="text"
            required
        />

        <x-select
            wire:model.live="form.type"
            label="{{ __('Storage Type') }}"
            :options="$storageTypes"
            required
        />
    </div>

    <!-- Configuration -->
    <x-hr />

    <div class="space-y-4">
        <h3 class="text-lg font-semibold">{{ __('Configuration') }}</h3>

        @if($form->type === 'local')
            <!-- Local Storage Config -->
            <x-input
                wire:model="form.path"
                label="{{ __('Path') }}"
                placeholder="{{ __('e.g., /var/backups or /mnt/backup-storage') }}"
                type="text"
                required
            />

            <p class="text-sm opacity-70">
                {{ __('Specify the absolute path where backups will be stored on the local filesystem.') }}
            </p>
        @elseif($form->type === 's3')
            <!-- S3 Config -->
            <x-alert class="alert-info" icon="o-information-circle">
                {{ __('S3 credentials are configured via environment variables.') }}
                <x-slot:actions>
                    <x-button
                        label="{{ __('View S3 Configuration Docs') }}"
                        link="https://david-crty.github.io/databasement/self-hosting/configuration#s3-storage"
                        external
                        class="btn-ghost btn-sm"
                        icon="o-arrow-top-right-on-square"
                    />
                </x-slot:actions>
            </x-alert>

            <x-input
                wire:model="form.bucket"
                label="{{ __('S3 Bucket Name') }}"
                placeholder="{{ __('e.g., my-backup-bucket') }}"
                type="text"
                required
            />

            <x-input
                wire:model="form.prefix"
                label="{{ __('Prefix (Optional)') }}"
                placeholder="{{ __('e.g., backups/production/') }}"
                type="text"
            />

            <p class="text-sm opacity-70">
                {{ __('The prefix is prepended to all backup file paths in the S3 bucket.') }}
            </p>
        @endif

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
