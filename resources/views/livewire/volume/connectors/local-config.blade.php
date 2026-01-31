<div class="space-y-4">
    <x-input
        wire:model="config.path"
        label="{{ __('Path') }}"
        placeholder="{{ __('e.g., /var/backups or /mnt/backup-storage') }}"
        type="text"
        :disabled="$readonly"
        required
    />

    @unless($readonly)
        <p class="text-sm opacity-70">
            {{ __('Specify the absolute path where backups will be stored on the local filesystem.') }}
        </p>
    @endunless
</div>
