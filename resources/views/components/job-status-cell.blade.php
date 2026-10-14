@props(['job', 'status' => null])

@php
    $jobStatus = $job?->status?->value ?? 'pending';
@endphp

<x-job-status-indicator :status="$status ?? $jobStatus" />

@if($jobStatus === 'running' && $job?->started_at)
    <div class="text-xs text-warning font-mono mt-1">{{ $job->started_at->diffForHumans(null, true) }}</div>
@endif
