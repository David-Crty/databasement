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
        'relative rounded-lg transition-all px-3 py-3',
        $horizontal ? 'flex items-center gap-3 text-left' : 'flex flex-col items-center gap-1.5 text-center',
        $active ? "bg-base-100 shadow-sm ring-1 {$activeRing}" : 'hover:bg-base-100/50',
        $disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer',
        'has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary has-[:focus-visible]:ring-offset-2 has-[:focus-visible]:ring-offset-base-200',
    ];
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
    </span>

    @if($active)
        <x-icon name="s-check-circle" class="w-4 h-4 {{ $activeText }} {{ $horizontal ? 'shrink-0' : 'absolute top-2 right-2' }}" />
    @endif
</label>
