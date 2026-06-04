@props(['label', 'description' => null, 'badge' => null, 'badgeClasses' => 'badge-warning badge-soft badge-xs'])

<div class="grid grid-cols-1 items-start gap-4 py-4 md:grid-cols-2">
    <div>
        <div class="flex items-center gap-2 text-sm font-semibold leading-tight">
            <span>{{ $label }}</span>
            @if ($badge)
                <span class="badge {{ $badgeClasses }}">{{ $badge }}</span>
            @endif
        </div>
        @if ($description)
            <div class="mt-1 text-sm leading-relaxed text-base-content/70">{{ $description }}</div>
        @endif
    </div>
    {{ $slot }}
</div>
