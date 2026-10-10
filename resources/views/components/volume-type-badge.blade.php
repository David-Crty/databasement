@props(['volume'])

<span {{ $attributes->class(['badge whitespace-nowrap']) }}>
    <x-volume-type-icon :type="$volume->type" class="w-3.5 h-3.5" />
    {{ $volume->getVolumeType()?->label() ?? $volume->type }}
</span>
