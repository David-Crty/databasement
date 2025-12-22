@props(['type', 'class' => 'w-5 h-5'])

@php
    $type = strtolower($type);
@endphp

@if($type === 'mysql' || $type === 'mariadb')
    <x-devicon-mysql {{ $attributes->merge(['class' => $class]) }} />
@elseif($type === 'postgresql' || $type === 'postgres')
    <x-devicon-postgresql {{ $attributes->merge(['class' => $class]) }} />
@elseif($type === 'sqlite')
    <x-devicon-sqlite {{ $attributes->merge(['class' => $class]) }} />
@else
    <x-icon name="o-circle-stack" {{ $attributes->merge(['class' => $class]) }} />
@endif
