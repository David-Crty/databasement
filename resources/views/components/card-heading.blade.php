@props(['title' => null, 'subtitle' => null])

{{-- Mary's x-card header keeps title and menu on one row; this stacks them on small screens. --}}
<div class="flex flex-col gap-3 pb-5 sm:flex-row sm:items-center sm:justify-between">
    <div class="min-w-0">
        @if ($title)
            <div class="text-xl font-bold">{{ $title }}</div>
        @endif

        @if ($subtitle)
            <div class="mt-1 text-sm text-base-content/50">{{ $subtitle }}</div>
        @endif
    </div>

    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap items-center justify-end gap-2 sm:shrink-0">
            {{ $slot }}
        </div>
    @endif
</div>
