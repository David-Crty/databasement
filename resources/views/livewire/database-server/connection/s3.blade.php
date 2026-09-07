@props(['form', 'isEdit' => false])

<!-- S3-compatible object storage connection fields -->
<div class="grid gap-4 md:grid-cols-2">
    <x-input
        wire:model="form.host"
        :label="__('Endpoint')"
        :hint="__('Hostname or IP of your S3-compatible server (e.g. minio.example.com)')"
        :placeholder="__('e.g., minio.example.com or s3.us-west-004.backblazeb2.com')"
        type="text"
        required
    />

    <x-input
        wire:model="form.port"
        :label="__('Port')"
        :hint="__('MinIO defaults to 9000; B2 S3 API uses 443')"
        :placeholder="__('e.g., 9000')"
        type="number"
        min="1"
        max="65535"
        required
    />
</div>

<div class="grid gap-4 md:grid-cols-2">
    <x-input
        wire:model="form.username"
        :label="__('Access Key ID')"
        :placeholder="__('S3 access key')"
        type="text"
        required
        autocomplete="off"
    />

    <x-password
        wire:model="form.password"
        :label="__('Secret Access Key')"
        :placeholder="$isEdit ? __('Leave blank to keep current') : __('S3 secret key')"
        :required="!$isEdit"
        autocomplete="off"
    />
</div>

<div class="grid gap-4 md:grid-cols-2">
    <x-input
        wire:model="form.s3_bucket"
        :label="__('Bucket')"
        placeholder="my-bucket"
        required
    />

    <x-input
        wire:model="form.s3_region"
        :label="__('Region')"
        placeholder="us-east-1"
    />

    <x-input
        wire:model="form.s3_prefix"
        :label="__('Prefix (optional)')"
        :hint="__('Limit access to this subfolder of the bucket. Blank targets the whole bucket.')"
        placeholder="photos/"
    />

    <x-checkbox
        wire:model="form.s3_use_path_style_endpoint"
        :label="__('Use path-style endpoint')"
        :hint="__('Required by MinIO and most non-AWS S3-compatible services.')"
    />
</div>

<div class="mt-2">
    <x-checkbox
        wire:model="form.ssl_enabled"
        :label="__('Connect over HTTPS / TLS')"
        :hint="__('Turn off for a local MinIO served over plain HTTP.')"
    />
</div>
