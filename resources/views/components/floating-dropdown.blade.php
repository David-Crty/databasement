@props([
    'right' => false,
    'top' => false,
    'offset' => 6,
])

@php
    $placement = match (true) {
        $top && $right => 'top-end',
        $top => 'top-start',
        $right => 'bottom-end',
        default => 'bottom-start',
    };
@endphp

<div x-data="{ open: false }" class="inline-block">
    <div x-ref="trigger" @click="open = !open" class="inline-flex">
        {{ $trigger }}
    </div>

    <template x-teleport="body">
        <ul
            x-show="open"
            x-cloak
            x-anchor.{{ $placement }}.offset.{{ (int) $offset }}="$refs.trigger"
            x-transition.opacity.duration.100ms
            @click="open = false"
            @click.outside="if (! $refs.trigger.contains($event.target)) open = false"
            @keydown.escape.window="open = false"
            class="z-50 p-2 shadow menu border border-base-content/10 bg-base-100 rounded-box w-auto min-w-max"
        >
            {{ $slot }}
        </ul>
    </template>
</div>
