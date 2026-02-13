@props(['type', 'class' => 'w-5 h-5'])

@php
    $volumeType = $type instanceof \App\Enums\VolumeType ? $type : \App\Enums\VolumeType::from($type);
    $iconName = $volumeType->icon();
@endphp

<x-icon :name="$iconName" {{ $attributes->merge(['class' => $class]) }} />
