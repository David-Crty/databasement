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
        :hint="__('The ID of a Google Drive folder to store backups in. Leave blank to use the root of the drive.')"
        type="text"
        :disabled="$readonly"
    />

    @unless($readonly)
        <x-alert class="alert-info" icon="o-information-circle">
            {{ __('To obtain these credentials, create an OAuth 2.0 Client in the Google Cloud Console and enable the Google Drive API. To generate a refresh token, open the OAuth Playground, select "Use your own OAuth credentials" in the settings, and enter the same Client ID and Client Secret. Add https://developers.google.com/oauthplayground as an authorized redirect URI for the client.') }}
        </x-alert>
    @endunless
</div>
