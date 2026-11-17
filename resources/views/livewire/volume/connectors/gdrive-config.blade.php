<div class="space-y-4">
    <x-input
        wire:model="{{ $configPrefix }}.client_id"
        :label="__('Client ID')"
        :placeholder="__('Google OAuth Client ID')"
        type="text"
        :disabled="$readonly"
        required
    />

    <x-password
        wire:model="{{ $configPrefix }}.client_secret"
        :label="__('Client Secret')"
        :placeholder="$isEditing ? __('Leave blank to keep current') : __('Google OAuth Client Secret')"
        :disabled="$readonly"
        :required="!$isEditing"
    />

    <x-password
        wire:model="{{ $configPrefix }}.refresh_token"
        :label="__('Refresh Token')"
        :placeholder="$isEditing ? __('Leave blank to keep current') : __('OAuth Refresh Token')"
        :disabled="$readonly"
        :required="!$isEditing"
    />

    <x-input
        wire:model="{{ $configPrefix }}.folder_id"
        :label="__('Folder ID (Optional)')"
        :placeholder="__('e.g., 1aBcDeFgHiJkLmNoPqRsTuVwXyZ')"
        :hint="__('The ID of a personal Google Drive folder to store backups in. Shared drives are not supported. Leave blank to use the root of the drive.')"
        type="text"
        :disabled="$readonly"
    />

    @unless($readonly)
        <x-alert class="alert-info" icon="o-information-circle">
            {{ __('These credentials come from your own Google Cloud OAuth app, which must be published or its refresh token expires after 7 days.') }}
            <a href="https://david-crty.github.io/databasement/user-guide/volumes/#google-drive" target="_blank" rel="noopener" class="link font-medium">{{ __('Setup guide') }}</a>
        </x-alert>
    @endunless
</div>
