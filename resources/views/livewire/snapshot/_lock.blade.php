@php
    $canLock = auth()->user()->can('lock', $snapshot);
@endphp

@if($snapshot->locked)
    <button
        type="button"
        class="badge badge-xs gap-1"
        @if($canLock)
            wire:click="toggleLock('{{ $snapshot->id }}')"
            title="{{ __('Kept out of automatic cleanup. Click to unlock.') }}"
        @else
            disabled
            title="{{ __('Kept out of automatic cleanup.') }}"
        @endif
    >
        <x-icon name="s-lock-closed" class="w-3 h-3" />
        {{ __('Locked') }}
    </button>
@elseif($canLock)
    <x-button
        :label="__('Lock')"
        icon="o-lock-open"
        :tooltip="__('Keep this snapshot out of automatic cleanup')"
        wire:click="toggleLock('{{ $snapshot->id }}')"
        class="btn-ghost btn-xs font-normal text-base-content/50 transition-opacity hover:text-primary md:opacity-0 md:group-hover:opacity-100 md:focus-visible:opacity-100"
    />
@endif
