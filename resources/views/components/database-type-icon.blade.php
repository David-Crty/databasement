@props(['type', 'class' => 'w-5 h-5'])

@php
    $type = $type instanceof \App\Enums\DatabaseType ? $type->value : strtolower($type);
@endphp

@if($type === 'mysql')
    <x-devicon-mysql {{ $attributes->merge(['class' => $class]) }} />
@elseif($type === 'postgres')
    <x-devicon-postgresql {{ $attributes->merge(['class' => $class]) }} />
@elseif($type === 'sqlite')
    <x-devicon-sqlite {{ $attributes->merge(['class' => $class]) }} />
@else
    <x-icon name="o-circle-stack" {{ $attributes->merge(['class' => $class]) }} />
@endif
