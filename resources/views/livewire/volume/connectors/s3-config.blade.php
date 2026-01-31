<div class="space-y-4">
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
        wire:model="config.bucket"
        label="{{ __('S3 Bucket Name') }}"
        placeholder="{{ __('e.g., my-backup-bucket') }}"
        type="text"
        :disabled="$readonly"
        required
    />

    <x-input
        wire:model="config.prefix"
        label="{{ __('Prefix (Optional)') }}"
        placeholder="{{ __('e.g., backups/production/') }}"
        type="text"
        :disabled="$readonly"
    />

    @unless($readonly)
        <p class="text-sm opacity-70">
            {{ __('The prefix is prepended to all backup file paths in the S3 bucket.') }}
        </p>
    @endunless
</div>
