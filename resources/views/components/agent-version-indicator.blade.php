@props(['agent'])

@php
    $status = $agent->versionStatus();
    $label = match (true) {
        $status === 'legacy' => __('Outdated'),
        $status === 'dev' => $agent->commit_hash ? \Illuminate\Support\Str::substr($agent->commit_hash, 0, 7) : 'dev',
        $agent->version !== null => 'v'.$agent->version,
        default => null,
    };
    [$badgeClass, $icon] = match ($status) {
        'current' => ['badge-success badge-soft', 'o-check-circle'],
        'outdated', 'legacy' => ['badge-warning badge-soft', 'o-exclamation-triangle'],
        'newer' => ['badge-info badge-soft', 'o-arrow-up-circle'],
        default => ['badge-ghost', null],
    };
    $serverVersion = config('app.version');
@endphp

@if($label === null)
    <span class="text-base-content/50">—</span>
@else
    <x-badge :value="$label" :icon="$icon" class="{{ $badgeClass }} badge-sm gap-1 whitespace-nowrap font-mono" />
    @if(($status === 'outdated' || $status === 'legacy') && \App\Models\Agent::minorVersion($serverVersion) !== null)
        <div class="text-sm text-base-content/70 mt-1">
            {{ __('Server runs :version', ['version' => 'v'.ltrim((string) $serverVersion, 'v')]) }}
        </div>
    @elseif($status === 'dev')
        <div class="text-sm text-base-content/70 mt-1">{{ __('Development build') }}</div>
    @endif
@endif
