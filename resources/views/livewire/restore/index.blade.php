<div wire:poll.5s>
    @if($errorMessage)
        <x-alert title="{{ $errorMessage }}" class="alert-error mb-4" icon="o-x-circle" />
    @endif

    <x-header :title="__('Restores')" separator progress-indicator>
        <x-slot:actions>
            <div class="hidden lg:flex items-center gap-2">
                @include('livewire.restore._filters', ['variant' => 'desktop'])
            </div>
            @can('create', \App\Models\Restore::class)
                <x-button
                    :label="__('New Restore')"
                    icon="o-plus"
                    wire:click="openNewRestore"
                    class="btn-primary btn-sm"
                />
            @endcan
        </x-slot:actions>
    </x-header>

    <div class="lg:hidden mb-4" x-data="{ showFilters: false }">
        @include('livewire.restore._filters', ['variant' => 'mobile'])
    </div>

    <x-card shadow>
        <x-table :headers="$headers" :rows="$restores" :sort-by="$sortBy" with-pagination>
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($search || $statusFilter !== '' || $sourceServerFilter !== '' || $targetServerFilter !== '' || $dbTypeFilter !== '')
                        {{ __('No restores found matching your filters.') }}
                    @else
                        {{ __('No restores yet. Trigger one from a snapshot or from the "New Restore" button.') }}
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_created_at', $restore)
                <div class="table-cell-primary">{{ \App\Support\Formatters::humanDate($restore->created_at) }}</div>
                <div class="text-sm text-base-content/70">{{ $restore->created_at->diffForHumans() }}</div>
            @endscope

            @scope('cell_source', $restore)
                @if($restore->snapshot)
                    <div class="flex items-center gap-2">
                        <x-icon :name="$restore->snapshot->database_type->icon()" class="w-5 h-5" />
                        <div>
                            <div class="table-cell-primary">{{ $restore->snapshot->databaseServer?->name ?? '?' }}</div>
                            <div class="text-sm text-base-content/70">{{ $restore->snapshot->database_name }}</div>
                            <div class="text-xs text-base-content/50">{{ \App\Support\Formatters::humanDate($restore->snapshot->created_at) }}</div>
                        </div>
                    </div>
                @else
                    <span class="text-base-content/50">{{ __('(snapshot deleted)') }}</span>
                @endif
            @endscope

            @scope('cell_target', $restore)
                @if($restore->targetServer)
                    <div>
                        <div class="table-cell-primary">{{ $restore->targetServer->name }}</div>
                        <div class="text-sm text-base-content/70">{{ $restore->schema_name }}</div>
                    </div>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_status', $restore)
                @php $status = $restore->job?->status ?? 'unknown'; @endphp
                @if($status === 'completed')
                    <x-badge value="{{ __('Completed') }}" class="badge-success" />
                @elseif($status === 'failed')
                    <x-badge value="{{ __('Failed') }}" class="badge-error" />
                @elseif($status === 'running')
                    <div class="badge badge-warning gap-1">
                        <x-loading class="loading-spinner loading-xs" />
                        {{ __('Running') }}
                    </div>
                @else
                    <x-badge value="{{ __('Pending') }}" class="badge-info" />
                @endif
            @endscope

            @scope('cell_duration_ms', $restore)
                @php $job = $restore->job; @endphp
                @if($job?->status === 'running' && $job->started_at)
                    <span class="font-mono text-sm text-warning">{{ $job->started_at->diffForHumans(null, true) }}</span>
                @elseif($job?->getHumanDuration())
                    <span class="font-mono text-sm">{{ $job->getHumanDuration() }}</span>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_triggered_by', $restore)
                @if($restore->triggeredBy)
                    <span class="text-sm">{{ $restore->triggeredBy->name }}</span>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('actions', $restore)
                <div class="flex gap-2 justify-end">
                    <x-button
                        icon="o-document-text"
                        wire:click="viewLogs('{{ $restore->job?->id }}')"
                        :tooltip="__('View Logs')"
                        class="btn-ghost btn-sm"
                        :class="empty($restore->job?->logs) ? 'opacity-30' : ''"
                        :disabled="empty($restore->job?->logs)"
                    />
                    @can('delete', $restore)
                        <x-button
                            icon="o-trash"
                            wire:click="confirmDeleteRestore('{{ $restore->id }}')"
                            :tooltip="__('Delete')"
                            class="btn-ghost btn-sm text-error"
                        />
                    @endcan
                </div>
            @endscope
        </x-table>
    </x-card>

    @include('partials.job-logs-modal')

    <x-delete-confirmation-modal
        :title="__('Delete Restore')"
        :message="__('Are you sure you want to delete this restore record?')"
        onConfirm="deleteRestore"
    />

    <livewire:restore.modal />
</div>
