@props(['icon', 'title'])

<div class="flex items-center gap-2.5 border-b border-base-200 px-4 py-3">
    <x-icon :name="$icon" class="w-4 h-4 opacity-60" />
    <h2 class="text-sm font-semibold">{{ $title }}</h2>
</div>
