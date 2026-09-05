@php
    $canLock = auth()->user()->can('lock', $snapshot);
@endphp

@if($snapshot->locked)
    <x-button
        :label="__('Locked')"
        icon="s-lock-closed"
        :tooltip="$canLock ? __('Kept out of automatic cleanup. Click to unlock.') : __('Kept out of automatic cleanup.')"
        :disabled="! $canLock"
        wire:click="toggleLock('{{ $snapshot->id }}')"
        class="btn-ghost btn-xs font-normal text-base-content/50 hover:text-primary"
    />
@elseif($canLock)
    <x-button
        :label="__('Lock')"
        icon="o-lock-open"
        :tooltip="__('Keep this snapshot out of automatic cleanup')"
        wire:click="toggleLock('{{ $snapshot->id }}')"
        class="btn-ghost btn-xs font-normal text-base-content/50 transition-opacity hover:text-primary md:opacity-0 md:group-hover:opacity-100 md:focus-visible:opacity-100"
    />
@endif
