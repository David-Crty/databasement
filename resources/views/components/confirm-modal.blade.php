@props([
    'model',
    'title',
    'message' => '',
    'onConfirm',
    'confirmLabel',
    'confirmClass' => 'btn-error',
    'cancelLabel' => null,
    'onCancel' => null,
    'blockedReason' => '',
])

<x-modal wire:model="{{ $model }}" :title="$title" class="backdrop-blur">
    @if($blockedReason !== '')
        <x-alert icon="o-exclamation-triangle" class="alert-warning">
            {{ $blockedReason }}
        </x-alert>
    @else
        @if($message !== '')
            <p>{{ $message }}</p>
        @endif

        {{ $slot }}
    @endif

    <x-slot:actions>
        @if($onCancel)
            <x-button :label="$cancelLabel ?? __('Cancel')" wire:click="{{ $onCancel }}" spinner />
        @else
            <x-button :label="$cancelLabel ?? __('Cancel')" @click="$wire.{{ $model }} = false" />
        @endif

        @if($blockedReason === '')
            <x-button :label="$confirmLabel" class="{{ $confirmClass }}" wire:click="{{ $onConfirm }}" spinner />
        @endif
    </x-slot:actions>
</x-modal>
