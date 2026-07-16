@props([
    'active' => false,
    'color' => 'primary',
    'icon' => null,
    'label',
    'hint' => null,
    'horizontal' => false,
    'disabled' => false,
    'value' => null,
    'name' => null,
])

@php
    // Per-color Tailwind classes. Listed as literals so Tailwind's JIT scanner picks them up.
    [$activeRing, $activeIconBg, $activeText] = match ($color) {
        'info'    => ['ring-info/40',    'bg-info/10',    'text-info'],
        'success' => ['ring-success/40', 'bg-success/10', 'text-success'],
        'warning' => ['ring-warning/40', 'bg-warning/10', 'text-warning'],
        'error'   => ['ring-error/40',   'bg-error/10',   'text-error'],
        'default' => ['ring-base-300',   'bg-base-300',   'text-base-content/70'],
        default   => ['ring-primary/40', 'bg-primary/10', 'text-primary'],
    };

    // Native radio group name; falls back to the wire:model target so callers don't repeat it.
    $wireModelAttrs = $attributes->whereStartsWith('wire:model')->getAttributes();
    $resolvedName = $name ?? (empty($wireModelAttrs) ? null : reset($wireModelAttrs));

    $labelClasses = [
        'group relative rounded-lg transition-shadow duration-300 ease-out px-3 py-3',
        $horizontal ? 'flex items-center gap-3 text-left' : 'flex flex-col items-center gap-1.5 text-center',
        $active ? "bg-base-100 shadow-sm ring-1 {$activeRing}" : 'hover:bg-base-100 hover:shadow-md',
        $disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer',
        'has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-base-200',
    ];

    // The check is shown for the active card, and faded in on hover for selectable
    // (non-active, enabled) cards to hint they can be picked. Disabled cards keep it hidden.
    $checkClasses = match (true) {
        $active => $activeText,
        $disabled => 'opacity-0',
        default => 'text-base-content/30 opacity-0 group-hover:opacity-100',
    };
@endphp

<label {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => implode(' ', $labelClasses)]) }}>
    <input
        type="radio"
        @if($resolvedName) name="{{ $resolvedName }}" @endif
        value="{{ $value }}"
        @checked($active)
        @disabled($disabled)
        class="sr-only"
        {{ $attributes->whereStartsWith('wire:model') }}
    />

    @if($icon)
        <span class="shrink-0 rounded-md p-1.5 {{ $active ? "{$activeIconBg} {$activeText}" : 'bg-base-100 text-base-content/60' }}">
            <x-icon :name="$icon" class="w-5 h-5" />
        </span>
    @endif

    <span class="{{ $horizontal ? 'flex-1 min-w-0' : 'block' }}">
        <span class="block text-sm font-semibold leading-tight {{ $active ? 'text-base-content' : 'text-base-content/70' }}">{{ $label }}</span>
        @if($hint)
            <span class="block text-xs mt-0.5 leading-snug {{ $active ? 'text-base-content/60' : 'text-base-content/40' }}">{{ $hint }}</span>
        @endif
        @if(trim((string) $slot) !== '')
            <span class="mt-1.5 block">{{ $slot }}</span>
        @endif
    </span>

    <x-icon
        name="s-check-circle"
        class="w-4 h-4 transition-opacity {{ $horizontal ? 'shrink-0' : 'absolute top-2 right-2' }} {{ $checkClasses }}"
    />
</label>
