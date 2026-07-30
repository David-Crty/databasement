@props(['file'])

@php
    [$badgeClass, $tooltip] = match (true) {
        $file->status === \App\Enums\SnapshotFileStatus::Failed => [
            'badge-error',
            $file->error ?: __('Upload to this volume failed'),
        ],
        $file->status === \App\Enums\SnapshotFileStatus::Pending => [
            'badge-ghost opacity-60',
            __('Upload pending'),
        ],
        ! $file->file_exists => [
            'badge-warning',
            __('Backup file not found on volume'),
        ],
        default => [
            'badge-ghost',
            $file->volume->getVolumeType()->label(),
        ],
    };
@endphp

<span class="badge badge-xs gap-1 cursor-help {{ $badgeClass }}" title="{{ $tooltip }}">
    <x-volume-type-icon :type="$file->volume->type" class="w-3 h-3" />
    {{ $file->volume->name }}
</span>
