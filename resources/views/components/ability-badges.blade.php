@use('App\Enums\Ability')

@props([
    // List of ability names (slugs), in any order. Unknown names are shown verbatim.
    'abilities' => [],
    // How many badges to show before collapsing the rest behind "show more".
    'visible' => 4,
    // Text shown when the list is empty.
    'empty' => null,
])

@php
    // Display in catalogue order (Operations first, then Configuration) so the
    // badges — and which ones fall under "show more" — are stable everywhere.
    $order = array_flip(Ability::names());

    $names = collect($abilities)
        ->map(fn ($name) => (string) $name)
        ->sortBy(fn (string $name) => $order[$name] ?? PHP_INT_MAX)
        ->values();
@endphp

@if($names->isEmpty())
    <span class="text-sm italic text-base-content/50">{{ $empty ?? __('No abilities') }}</span>
@else
    <div x-data="{ expanded: false }" {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-1']) }}>
        @foreach($names as $index => $name)
            <span @if($index >= $visible) x-show="expanded" x-cloak @endif
                  class="badge badge-sm badge-ghost whitespace-nowrap">
                {{ Ability::tryFrom($name)?->label() ?? $name }}
            </span>
        @endforeach

        @if($names->count() > $visible)
            <button type="button" x-on:click.prevent.stop="expanded = ! expanded"
                    class="badge badge-sm badge-ghost whitespace-nowrap cursor-pointer hover:bg-base-300">
                <span x-show="!expanded">+{{ $names->count() - $visible }} {{ __('more') }}</span>
                <span x-show="expanded" x-cloak>{{ __('Show less') }}</span>
            </button>
        @endif
    </div>
@endif
