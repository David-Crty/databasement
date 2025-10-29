@props([
    'padding' => true,
])

@php
    $baseClasses = 'rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900';
    $paddingClass = $padding ? 'p-6' : '';
@endphp

<div {{ $attributes->merge(['class' => "{$baseClasses} {$paddingClass}"]) }}>
    {{ $slot }}
</div>
