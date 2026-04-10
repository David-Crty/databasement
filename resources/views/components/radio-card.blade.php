@props([
    'active' => false,
    'color' => 'primary',
    'icon' => null,
    'label',
    'hint' => null,
    'horizontal' => false,
    'disabled' => false,
    'value' => null,
])

@php
    $colorMap = [
        'primary' => [
            'ring' => 'ring-primary/40',
            'iconBg' => 'bg-primary/10',
            'iconText' => 'text-primary',
            'check' => 'text-primary',
        ],
        'info' => [
            'ring' => 'ring-info/40',
            'iconBg' => 'bg-info/10',
            'iconText' => 'text-info',
            'check' => 'text-info',
        ],
        'success' => [
            'ring' => 'ring-success/40',
            'iconBg' => 'bg-success/10',
            'iconText' => 'text-success',
            'check' => 'text-success',
        ],
        'warning' => [
            'ring' => 'ring-warning/40',
            'iconBg' => 'bg-warning/10',
            'iconText' => 'text-warning',
            'check' => 'text-warning',
        ],
        'error' => [
            'ring' => 'ring-error/40',
            'iconBg' => 'bg-error/10',
            'iconText' => 'text-error',
            'check' => 'text-error',
        ],
        'default' => [
            'ring' => 'ring-base-300',
            'iconBg' => 'bg-base-300',
            'iconText' => 'text-base-content/70',
            'check' => 'text-base-content',
        ],
    ];
    $c = $colorMap[$color] ?? $colorMap['primary'];

    $cardClasses = $active
        ? 'bg-base-100 shadow-sm ring-1 '.$c['ring']
        : ($disabled ? '' : 'hover:bg-base-100/50');

    if ($disabled) {
        $cardClasses .= ' opacity-50 cursor-not-allowed';
    } else {
        $cardClasses .= ' cursor-pointer';
    }

    $iconChipClasses = $active
        ? $c['iconBg'].' '.$c['iconText']
        : 'bg-base-100 text-base-content/60';

    $labelColor = $active ? 'text-base-content' : 'text-base-content/70';
    $hintColor = $active ? 'text-base-content/60' : 'text-base-content/40';

    $layoutClasses = $horizontal
        ? 'flex items-center gap-3 text-left px-3 py-3'
        : 'flex flex-col items-center gap-1.5 text-center px-3 py-3';
@endphp

<label
    {{ $attributes->whereDoesntStartWith('wire:model')->merge(['class' => 'relative rounded-lg transition-all '.$layoutClasses.' '.$cardClasses]) }}
>
    <input
        type="radio"
        value="{{ $value }}"
        @checked($active)
        @disabled($disabled)
        class="sr-only"
        {{ $attributes->whereStartsWith('wire:model') }}
    />

    @if($icon)
        <span class="shrink-0 rounded-md p-1.5 {{ $iconChipClasses }}">
            <x-icon :name="$icon" class="w-5 h-5" />
        </span>
    @endif

    <span class="{{ $horizontal ? 'flex-1 min-w-0' : 'block' }}">
        <span class="block text-sm font-semibold leading-tight {{ $labelColor }}">{{ $label }}</span>
        @if($hint)
            <span class="block text-xs mt-0.5 leading-snug {{ $hintColor }}">{{ $hint }}</span>
        @endif
    </span>

    @if($active)
        <x-icon
            name="s-check-circle"
            class="w-4 h-4 {{ $c['check'] }} {{ $horizontal ? 'shrink-0' : 'absolute top-2 right-2' }}"
        />
    @endif
</label>
