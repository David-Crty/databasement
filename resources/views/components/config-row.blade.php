@props(['label', 'description' => null])

<div class="grid grid-cols-1 items-start gap-4 py-4 md:grid-cols-2">
    <div>
        <div class="text-sm font-semibold leading-tight">{{ $label }}</div>
        @if ($description)
            <div class="mt-1 text-sm leading-relaxed text-base-content/70">{{ $description }}</div>
        @endif
    </div>
    {{ $slot }}
</div>
