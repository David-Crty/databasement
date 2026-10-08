@props(['agent'])

@php
    $status = $agent->versionStatus();
    $serverVersion = \App\Models\Agent::serverVersion();
    [$badgeClass, $icon] = match ($status) {
        'current' => ['badge-success badge-soft', 'o-check-circle'],
        'outdated', 'legacy' => ['badge-warning badge-soft', 'o-exclamation-triangle'],
        'newer' => ['badge-info badge-soft', 'o-arrow-up-circle'],
        default => ['badge-ghost', null],
    };
@endphp

@if($status === 'never')
    <span class="text-base-content/50">—</span>
@else
    <x-badge :value="$agent->versionLabel()" :icon="$icon" class="{{ $badgeClass }} badge-sm gap-1 whitespace-nowrap font-mono" />
    @if($agent->needsUpdate() && $serverVersion !== null)
        <div class="text-sm text-base-content/70 mt-1">
            {{ __('Server runs :version', ['version' => $serverVersion]) }}
        </div>
    @elseif($status === 'dev')
        <div class="text-sm text-base-content/70 mt-1">{{ __('Development build') }}</div>
    @endif
@endif
