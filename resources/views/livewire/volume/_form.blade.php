@props(['form', 'submitLabel' => 'Save', 'cancelRoute' => 'volumes.index'])

<form wire:submit="save" class="space-y-6">
    <!-- Basic Information -->
    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Basic Information') }}</flux:heading>

        <flux:input
            wire:model="form.name"
            :label="__('Volume Name')"
            :placeholder="__('e.g., Production S3 Bucket')"
            type="text"
            required
            autofocus
        />
        @error('form.name') <flux:error>{{ $message }}</flux:error> @enderror

        <flux:select
            wire:model.live="form.type"
            :label="__('Storage Type')"
            required
        >
            <option value="local">Local Storage</option>
            <option value="s3">Amazon S3</option>
        </flux:select>
        @error('form.type') <flux:error>{{ $message }}</flux:error> @enderror
    </div>

    <!-- Configuration -->
    <flux:separator />

    <div class="space-y-4">
        <flux:heading size="lg">{{ __('Configuration') }}</flux:heading>

        @if($form->type === 'local')
            <!-- Local Storage Config -->
            <flux:input
                wire:model="form.path"
                :label="__('Path')"
                :placeholder="__('e.g., /var/backups or /mnt/backup-storage')"
                type="text"
                required
            />
            @error('form.path') <flux:error>{{ $message }}</flux:error> @enderror

            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Specify the absolute path where backups will be stored on the local filesystem.') }}
            </p>
        @elseif($form->type === 's3')
            <!-- S3 Config -->
            <flux:input
                wire:model="form.bucket"
                :label="__('S3 Bucket Name')"
                :placeholder="__('e.g., my-backup-bucket')"
                type="text"
                required
            />
            @error('form.bucket') <flux:error>{{ $message }}</flux:error> @enderror

            <flux:input
                wire:model="form.prefix"
                :label="__('Prefix (Optional)')"
                :placeholder="__('e.g., backups/production/')"
                type="text"
            />
            @error('form.prefix') <flux:error>{{ $message }}</flux:error> @enderror

            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('The prefix is prepended to all backup file paths in the S3 bucket.') }}
            </p>
        @endif
    </div>

    <!-- Submit Button -->
    <div class="flex items-center justify-end gap-3 pt-4">
        <flux:button variant="ghost" href="{{ route($cancelRoute) }}" wire:navigate>
            {{ __('Cancel') }}
        </flux:button>
        <flux:button variant="primary" type="submit">
            {{ __($submitLabel) }}
        </flux:button>
    </div>
</form>
