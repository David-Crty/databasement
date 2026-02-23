@props(['title', 'message', 'onConfirm', 'showKeepFiles' => false, 'snapshotCount' => 0])

<x-modal wire:model="showDeleteModal" :title="$title" class="backdrop-blur">
    <p>{{ $message }}</p>

    @if($snapshotCount > 0)
        <x-alert icon="o-exclamation-triangle" class="alert-warning mt-4">
            {{ trans_choice(':count snapshot will also be deleted.|:count snapshots will also be deleted.', $snapshotCount, ['count' => $snapshotCount]) }}
        </x-alert>
    @endif

    @if($showKeepFiles)
        <label class="flex items-start gap-3 mt-4 cursor-pointer">
            <input type="checkbox" wire:model="keepFiles" class="checkbox checkbox-sm mt-0.5" />
            <span class="text-sm">{{ __('Keep backup files on storage (only delete database records)') }}</span>
        </label>
    @endif

    {{ $slot }}

    <x-slot:actions>
        <x-button :label="__('Cancel')" @click="$wire.showDeleteModal = false" />
        <x-button :label="__('Delete')" class="btn-error" wire:click="{{ $onConfirm }}" />
    </x-slot:actions>
</x-modal>
