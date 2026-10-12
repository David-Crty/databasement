@props(['title', 'message' => '', 'onConfirm', 'showKeepFiles' => false, 'snapshotCount' => 0])

<x-confirm-modal model="showDeleteModal" :title="$title" :message="$message" :on-confirm="$onConfirm" :confirm-label="__('Delete')">
    @if($snapshotCount > 0)
        <x-alert icon="o-exclamation-triangle" class="alert-warning mt-4">
            {{ trans_choice(':count snapshot will also be deleted.|:count snapshots will also be deleted.', $snapshotCount, ['count' => $snapshotCount]) }}
        </x-alert>
    @endif

    {{ $slot }}

    @if($showKeepFiles)
        <label class="flex items-start gap-3 mt-4 cursor-pointer">
            <input type="checkbox" wire:model="keepFiles" class="checkbox checkbox-sm mt-0.5" />
            <span class="text-sm">{{ __('Keep backup files on storage (only delete database records)') }}</span>
        </label>
    @endif
</x-confirm-modal>
