@props([
    'status',
    'label' => null,
])

@php
    $status = $status instanceof \BackedEnum ? $status->value : $status;

    [$badgeClass, $icon, $defaultLabel, $useSpinner] = match ($status) {
        'completed' => ['badge-success', 'o-check-circle', __('Completed'), false],
        'failed' => ['badge-error', 'o-x-circle', __('Failed'), false],
        'running' => ['badge-warning', null, __('Running'), true],
        'pending' => ['badge-info', 'o-clock', __('Pending'), false],
        'skipped' => ['badge-warning', 'o-exclamation-triangle', __('Skipped'), false],
        'never' => ['badge-ghost', 'o-clock', __('Never run'), false],
        default => ['badge-ghost', 'o-clock', __(ucfirst((string) $status)), false],
    };
    $resolvedLabel = $label ?? $defaultLabel;
    $baseClass = $badgeClass.' badge-sm h-auto py-1 whitespace-normal text-center';
@endphp

@if($useSpinner)
    <x-badge {{ $attributes->class([$baseClass.' gap-1']) }}>
        <x-loading class="loading-spinner loading-xs shrink-0" />
        {{ $resolvedLabel }}
    </x-badge>
@else
    <x-badge :value="$resolvedLabel" :icon="$icon" {{ $attributes->class([$baseClass]) }} />
@endif
