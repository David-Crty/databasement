<x-button
    icon="o-document-text"
    wire:click="viewLogs('{{ $job?->id }}')"
    spinner
    :tooltip="__('View Logs')"
    class="btn-ghost btn-sm {{ $job ? '' : 'opacity-30' }}"
    :disabled="! $job"
/>
