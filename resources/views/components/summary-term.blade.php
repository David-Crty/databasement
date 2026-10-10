@props(['icon', 'label'])

<dt class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-base-content/50">
    <x-icon :name="$icon" class="w-3.5 h-3.5" />
    {{ $label }}
</dt>
