@props(['icon', 'label', 'server' => null, 'deletedLabel'])

<div class="flex items-center gap-2 min-w-0 flex-1">
    @if($server)
        <x-icon :name="$icon" class="w-5 h-5 shrink-0" />
        <div class="min-w-0">
            <div class="flex items-center gap-1.5">
                <span class="table-cell-primary truncate">{{ $label }}</span>
                {{ $slot }}
            </div>
            <a href="{{ route('database-servers.show', $server) }}" wire:navigate
               class="text-xs text-base-content/60 hover:text-primary hover:underline truncate block">
                {{ $server->name }}
            </a>
        </div>
    @else
        <span class="text-sm text-base-content/50 italic">{{ $deletedLabel }}</span>
    @endif
</div>
