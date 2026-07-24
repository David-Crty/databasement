<div class="space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-input
            wire:model="{{ $configPrefix }}.host"
            :label="__('Host')"
            :placeholder="__('e.g., fileserver.example.com')"
            type="text"
            :disabled="$readonly"
            required
        />

        <x-input
            wire:model="{{ $configPrefix }}.share"
            :label="__('Share')"
            :placeholder="__('e.g., backups')"
            type="text"
            :disabled="$readonly"
            required
        />
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-input
            wire:model="{{ $configPrefix }}.username"
            :label="__('Username')"
            :placeholder="__('e.g., backup-user')"
            type="text"
            :disabled="$readonly"
            required
        />

        <x-password
            wire:model="{{ $configPrefix }}.password"
            :label="__('Password')"
            :placeholder="$isEditing ? __('Leave blank to keep current') : ''"
            :disabled="$readonly"
            :required="!$isEditing"
        />
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-input
            wire:model="{{ $configPrefix }}.domain"
            :label="__('Domain / Workgroup')"
            placeholder="WORKGROUP"
            type="text"
            :disabled="$readonly"
        />

        <x-input
            wire:model="{{ $configPrefix }}.root"
            :label="__('Root Directory')"
            :placeholder="__('e.g., /databasement')"
            type="text"
            :disabled="$readonly"
        />
    </div>

    @unless($readonly)
        <p class="text-sm opacity-70">
            {{ __('Backups will be stored in the specified root directory inside the SMB share.') }}
        </p>
    @endunless
</div>
