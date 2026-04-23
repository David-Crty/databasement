@php
    $label = $backup->getDisplayLabel(false);
@endphp
<div
    class="flex items-center gap-1.5 rounded-md bg-base-200/60 border border-base-300 px-2 py-1.5 min-w-0"
    title="{{ $backup->getDisplayLabel() }}"
>
    <span class="text-xs font-semibold text-base-content truncate shrink min-w-0">
        <x-icon name="o-clock" class="w-3 h-3 text-primary/80" />
        {{ \Illuminate\Support\Str::limit($label['schedule'], 30) }}
    </span>
    <span class="max-sm:hidden text-xs font-semibold text-base-content truncate shrink min-w-0">
        <span class="text-base-content/30 text-[0.625rem]">→</span>
        <x-volume-type-icon :type="$backup->volume->type" class="w-3 h-3 text-primary/80" />
        {{ \Illuminate\Support\Str::limit($label['volume'], 30) }}
    </span>
    @if($label['databases'])
        <span class="max-md:hidden inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[0.625rem] font-medium leading-none bg-base-300/60 text-base-content/60 shrink-0">
            <x-icon name="o-circle-stack" class="w-2.5 h-2.5" />
            {{ \Illuminate\Support\Str::limit($label['databases'], 20) }}
        </span>
    @endif
    @if($label['retention'])
    <span class="max-md:hidden inline-flex items-center gap-1 rounded px-1.5 py-0.5 te5xt-[0.625rem] font-medium leading-none bg-info/10 text-info shrink-0">
            <x-icon name="o-archive-box" class="w-2.5 h-2.5" />
            {{ \Illuminate\Support\Str::limit($label['retention'], 15) }}
        </span>
    @endif
    @can('backup', $server)
        <x-button
            icon="o-arrow-down-tray"
            wire:click="runBackup('{{ $backup->id }}')"
            spinner
            tooltip="{{ __('Backup now') }}"
            class="btn-ghost btn-xs text-info ml-auto shrink-0 -mr-1"
        />
    @endcan
</div>
