@props(['icon', 'label'])

<li class="list-row">
    <x-icon :name="$icon" class="w-4 h-4 opacity-60" />
    <div class="min-w-0">
        <div class="text-xs uppercase font-semibold opacity-60">{{ $label }}</div>
        {{ $slot }}
    </div>
</li>
