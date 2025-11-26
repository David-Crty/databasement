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
