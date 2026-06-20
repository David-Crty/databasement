@props([
    'status',
    'label' => null,
])

@php
    [$badgeClass, $icon, $defaultLabel] = match ($status) {
        'online' => ['badge-success', 'o-signal', __('Online')],
        'offline' => ['badge-error', 'o-signal-slash', __('Offline')],
        'never' => ['badge-ghost', 'o-clock', __('Never connected')],
        default => ['badge-ghost', 'o-question-mark-circle', __(ucfirst((string) $status))],
    };
    $resolvedLabel = $label ?? $defaultLabel;
    $baseClass = $badgeClass.' badge-sm gap-1 whitespace-nowrap';
@endphp

<x-badge :value="$resolvedLabel" :icon="$icon" {{ $attributes->class([$baseClass]) }} />
