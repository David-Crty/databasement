@props(['job', 'status' => null, 'fileSize' => null])

@php
    $jobStatus = $job?->status?->value ?? 'pending';
    $duration = $job?->getHumanDuration();
    $fileSize = $jobStatus === 'completed' ? $fileSize : null;
@endphp

<x-job-status-indicator :status="$status ?? $jobStatus" />

@if($jobStatus === 'running' && $job?->started_at)
    <div class="text-xs text-warning font-mono mt-1">{{ $job->started_at->diffForHumans(null, true) }}</div>
@elseif($duration || $fileSize)
    <div class="flex items-center gap-3 text-xs text-base-content/60 mt-1">
        @if($duration)
            <span class="inline-flex items-center gap-1">
                <x-icon name="o-clock" class="w-3 h-3" />
                <span class="font-mono">{{ $duration }}</span>
            </span>
        @endif
        @if($fileSize)
            <span class="inline-flex items-center gap-1">
                <x-icon name="o-archive-box" class="w-3 h-3" />
                <span class="font-mono">{{ $fileSize }}</span>
            </span>
        @endif
    </div>
@endif
