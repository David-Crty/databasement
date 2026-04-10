@props(['label' => null])

<div
    role="radiogroup"
    @if($label) aria-label="{{ $label }}" @endif
    {{ $attributes->merge(['class' => 'grid gap-2 rounded-xl bg-base-200 p-2']) }}
>
    {{ $slot }}
</div>
