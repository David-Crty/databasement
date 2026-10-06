@if($job?->status?->isInProgress())
    @can('cancel', $job)
        <x-button
            icon="o-stop-circle"
            :label="$label ?? null"
            wire:click="confirmCancelJob('{{ $job->id }}')"
            spinner
            :tooltip="isset($label) ? null : __('Cancel')"
            class="btn-ghost btn-sm text-error"
        />
    @endcan
@endif
