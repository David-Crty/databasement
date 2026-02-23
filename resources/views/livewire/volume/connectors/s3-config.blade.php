<div class="space-y-4">
    <x-input
        wire:model="{{ $configPrefix }}.bucket"
        :label="__('S3 Bucket Name')"
        :placeholder="__('e.g., my-backup-bucket')"
        type="text"
        :disabled="$readonly"
        required
    />

    <x-input
        wire:model="{{ $configPrefix }}.prefix"
        :label="__('Prefix (Optional)')"
        :placeholder="__('e.g., backups/production/')"
        :hint="__('The prefix is prepended to all backup file paths in the S3 bucket.')"
        type="text"
        :disabled="$readonly"
    />

    <x-input
        wire:model="{{ $configPrefix }}.region"
        :label="__('AWS Region')"
        placeholder="us-east-1"
        :hint="__('The AWS region where your S3 bucket is located.')"
        type="text"
        :disabled="$readonly"
        required
    />

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <x-input
            wire:model="{{ $configPrefix }}.access_key_id"
            :label="__('Access Key ID')"
            :placeholder="__('Optional')"
            type="text"
            :disabled="$readonly"
        />

        <x-password
            wire:model="{{ $configPrefix }}.secret_access_key"
            :label="__('Secret Access Key')"
            :placeholder="$isEditing ? __('Leave blank to keep current') : __('Optional')"
            :disabled="$readonly"
        />
    </div>

    @unless($readonly)
        <x-alert class="alert-info" icon="o-information-circle">
            {{ __('Credentials are optional. When left blank, the AWS SDK default credential chain is used (environment variables, EC2/ECS instance roles, IRSA).') }}
        </x-alert>
    @endunless

    {{-- Advanced S3 Settings --}}
    <div x-data="{ showAdvanced: false }">
        <button
            type="button"
            class="btn btn-ghost btn-sm gap-1"
            x-on:click="showAdvanced = !showAdvanced"
        >
            <x-icon x-show="!showAdvanced" name="o-chevron-right" class="w-4 h-4" />
            <x-icon x-show="showAdvanced" name="o-chevron-down" class="w-4 h-4" />
            {{ __('Advanced S3 Settings') }}
        </button>

        <div x-show="showAdvanced" x-collapse class="mt-3 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-input
                    wire:model="{{ $configPrefix }}.custom_endpoint"
                    :label="__('Custom Endpoint')"
                    placeholder="https://s3.custom-provider.com"
                    :hint="__('For S3-compatible storage (MinIO, DigitalOcean Spaces, Backblaze B2, etc.).')"
                    type="text"
                    :disabled="$readonly"
                />

                <x-input
                    wire:model="{{ $configPrefix }}.public_endpoint"
                    :label="__('Public Endpoint')"
                    placeholder="https://s3-public.example.com"
                    :hint="__('Used for presigned download URLs when the internal endpoint differs from the public URL.')"
                    type="text"
                    :disabled="$readonly"
                />
            </div>

            <x-checkbox
                wire:model="{{ $configPrefix }}.use_path_style_endpoint"
                :label="__('Use Path-Style Endpoint')"
                :hint="__('Required for most S3-compatible providers (MinIO, etc.).')"
                :disabled="$readonly"
            />

            <x-input
                wire:model="{{ $configPrefix }}.custom_role_arn"
                :label="__('IAM Role ARN')"
                placeholder="arn:aws:iam::123456789012:role/my-role"
                :hint="__('Assume this IAM role via STS before accessing S3.')"
                type="text"
                :disabled="$readonly"
            />

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-input
                    wire:model="{{ $configPrefix }}.role_session_name"
                    :label="__('Role Session Name')"
                    placeholder="databasement"
                    :hint="__('Identifier for the assumed role session.')"
                    type="text"
                    :disabled="$readonly"
                />

                <x-input
                    wire:model="{{ $configPrefix }}.sts_endpoint"
                    :label="__('STS Endpoint')"
                    placeholder="https://sts.amazonaws.com"
                    :hint="__('Custom STS endpoint for role assumption.')"
                    type="text"
                    :disabled="$readonly"
                />
            </div>
        </div>
    </div>
</div>
