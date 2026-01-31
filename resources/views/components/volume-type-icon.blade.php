@props(['type', 'class' => 'w-5 h-5'])

@php
    $volumeType = $type instanceof \App\Enums\VolumeType ? $type : \App\Enums\VolumeType::tryFrom($type);
    $iconName = $volumeType?->icon() ?? 'o-circle-stack';
@endphp

<x-icon :name="$iconName" {{ $attributes->merge(['class' => $class]) }} />
