<div class="space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-input
            wire:model="{{ $configPrefix }}.host"
            label="{{ __('Host') }}"
            placeholder="{{ __('e.g., ftp.example.com') }}"
            type="text"
            :disabled="$readonly"
            required
        />

        <x-input
            wire:model="{{ $configPrefix }}.port"
            label="{{ __('Port') }}"
            placeholder="21"
            type="number"
            :disabled="$readonly"
        />
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-input
            wire:model="{{ $configPrefix }}.username"
            label="{{ __('Username') }}"
            placeholder="{{ __('e.g., backup-user') }}"
            type="text"
            :disabled="$readonly"
            required
        />

        <x-password
            wire:model="{{ $configPrefix }}.password"
            label="{{ __('Password') }}"
            placeholder="{{ $isEditing ? __('Leave blank to keep current') : '' }}"
            :disabled="$readonly"
            :required="!$isEditing"
        />
    </div>

    <x-input
        wire:model="{{ $configPrefix }}.root"
        label="{{ __('Root Directory') }}"
        placeholder="{{ __('e.g., /backups') }}"
        type="text"
        :disabled="$readonly"
    />

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-checkbox
            wire:model="{{ $configPrefix }}.ssl"
            label="{{ __('Use SSL/TLS (FTPS)') }}"
            :disabled="$readonly"
        />

        <x-checkbox
            wire:model="{{ $configPrefix }}.passive"
            label="{{ __('Passive Mode') }}"
            :disabled="$readonly"
        />
    </div>

    <x-input
        wire:model="{{ $configPrefix }}.timeout"
        label="{{ __('Connection Timeout (seconds)') }}"
        placeholder="90"
        type="number"
        :disabled="$readonly"
    />

    @unless($readonly)
        <p class="text-sm opacity-70">
            {{ __('Backups will be stored in the specified root directory on the FTP server. Enable SSL/TLS for encrypted connections (FTPS).') }}
        </p>
    @endunless
</div>
