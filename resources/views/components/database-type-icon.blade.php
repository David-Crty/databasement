@props(['type', 'class' => 'w-5 h-5'])

@php
    $dbType = $type instanceof \App\Enums\DatabaseType ? $type : \App\Enums\DatabaseType::from($type);
    $iconName = $dbType->icon();
@endphp

<x-icon :name="$iconName" {{ $attributes->merge(['class' => $class]) }} />
