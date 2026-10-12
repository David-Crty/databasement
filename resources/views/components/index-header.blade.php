@props(['title', 'search'])

{{-- A listing page's header with its search box: inline on desktop, below the header (and anything in `below`) on mobile. --}}
<x-header :title="$title" separator progress-indicator>
    <x-slot:actions>
        <div class="hidden sm:flex items-center gap-2">
            <x-input
                :placeholder="__('Search...')"
                wire:model.live.debounce="search"
                clearable
                icon="o-magnifying-glass"
                class="!input-sm w-48"
            />
            @if($search)
                <x-button
                    icon="o-x-mark"
                    wire:click="clear"
                    spinner
                    class="btn-ghost btn-sm"
                    :tooltip="__('Clear search')"
                />
            @endif
        </div>
        {{ $slot }}
    </x-slot:actions>
</x-header>

{{ $below ?? '' }}

<div class="sm:hidden mb-4">
    <x-input
        :placeholder="__('Search...')"
        wire:model.live.debounce="search"
        clearable
        icon="o-magnifying-glass"
    />
</div>
