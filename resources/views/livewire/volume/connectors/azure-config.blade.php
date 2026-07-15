<div class="space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-input
            wire:model="{{ $configPrefix }}.account_name"
            :label="__('Storage Account Name')"
            :placeholder="__('e.g., mystorageaccount')"
            type="text"
            :disabled="$readonly"
            required
        />

        <x-password
            wire:model="{{ $configPrefix }}.account_key"
            :label="__('Account Key')"
            :placeholder="$isEditing ? __('Leave blank to keep current') : ''"
            :disabled="$readonly"
            :required="!$isEditing"
        />
    </div>

    <x-input
        wire:model="{{ $configPrefix }}.container"
        :label="__('Container Name')"
        :placeholder="__('e.g., backups')"
        type="text"
        :disabled="$readonly"
        required
    />

    <x-input
        wire:model="{{ $configPrefix }}.prefix"
        :label="__('Prefix (Optional)')"
        :placeholder="__('e.g., backups/production/')"
        :hint="__('The prefix is prepended to all backup file paths in the container.')"
        type="text"
        :disabled="$readonly"
    />

    <x-input
        wire:model="{{ $configPrefix }}.endpoint_suffix"
        :label="__('Endpoint Suffix')"
        placeholder="core.windows.net"
        :hint="__('Change only for sovereign clouds (e.g., core.usgovcloudapi.net, core.chinacloudapi.cn).')"
        type="text"
        :disabled="$readonly"
    />

    <x-input
        wire:model="{{ $configPrefix }}.endpoint"
        :label="__('Custom Blob Endpoint')"
        placeholder="https://gateway.example.com/account"
        :hint="__('For Azure-compatible storage (Azurite emulator, self-hosted gateways). Overrides the endpoint suffix.')"
        type="text"
        :disabled="$readonly"
    />

    @unless($readonly)
        <p class="text-sm opacity-70">
            {{ __('Backups will be stored as blobs in the specified container. The account key can be found under Access keys in your Azure Storage account.') }}
        </p>
    @endunless
</div>
