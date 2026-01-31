<div class="space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-input
            wire:model="config.host"
            label="{{ __('Host') }}"
            placeholder="{{ __('e.g., sftp.example.com') }}"
            type="text"
            :disabled="$readonly"
            required
        />

        <x-input
            wire:model="config.port"
            label="{{ __('Port') }}"
            placeholder="22"
            type="number"
            :disabled="$readonly"
        />
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-input
            wire:model="config.username"
            label="{{ __('Username') }}"
            placeholder="{{ __('e.g., backup-user') }}"
            type="text"
            :disabled="$readonly"
            required
        />

        <x-password
            wire:model="config.password"
            label="{{ __('Password') }}"
            placeholder="{{ $isEditing ? __('Leave blank to keep current') : '' }}"
            :disabled="$readonly"
            :required="!$isEditing"
        />
    </div>

    <x-input
        wire:model="config.root"
        label="{{ __('Root Directory') }}"
        placeholder="{{ __('e.g., /home/backup-user/backups') }}"
        type="text"
        :disabled="$readonly"
    />

    <x-input
        wire:model="config.timeout"
        label="{{ __('Connection Timeout (seconds)') }}"
        placeholder="10"
        type="number"
        :disabled="$readonly"
    />

    @unless($readonly)
        <p class="text-sm opacity-70">
            {{ __('Backups will be stored in the specified root directory on the SFTP server.') }}
        </p>
    @endunless
</div>
