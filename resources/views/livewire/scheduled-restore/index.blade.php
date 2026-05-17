<div>
    <x-header :title="__('Scheduled Restores')" separator progress-indicator>
        <x-slot:actions>
            <div class="hidden lg:flex items-center gap-2">
                @include('livewire.scheduled-restore._filters', ['variant' => 'desktop'])
            </div>
            @can('create', \App\Models\ScheduledRestore::class)
                <x-button
                    :label="__('New Scheduled Restore')"
                    icon="o-plus"
                    wire:click="openCreate"
                    class="btn-primary btn-sm"
                />
            @endcan
        </x-slot:actions>
    </x-header>

    <div class="lg:hidden mb-4" x-data="{ showFilters: false }">
        @include('livewire.scheduled-restore._filters', ['variant' => 'mobile'])
    </div>

    <x-card shadow>
        <x-table :headers="$headers" :rows="$scheduledRestores" :sort-by="$sortBy" with-pagination>
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($search || $enabledFilter !== '' || $sourceServerFilter !== '' || $targetServerFilter !== '' || $dbTypeFilter !== '')
                        {{ __('No scheduled restores matching your filters.') }}
                    @else
                        {{ __('No scheduled restores yet. Create one to refresh a target server on a recurring schedule.') }}
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_name', $scheduledRestore)
                <div class="table-cell-primary">{{ $scheduledRestore->name }}</div>
            @endscope

            @scope('cell_source', $scheduledRestore)
                @if($scheduledRestore->sourceServer)
                    <div class="flex items-center gap-2">
                        <x-icon :name="$scheduledRestore->sourceServer->database_type->icon()" class="w-5 h-5" />
                        <div>
                            <div class="table-cell-primary">{{ $scheduledRestore->sourceServer->name }}</div>
                            <div class="text-sm text-base-content/70">{{ $scheduledRestore->source_database_name ?? __('(any database)') }}</div>
                        </div>
                    </div>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_target', $scheduledRestore)
                @if($scheduledRestore->targetServer)
                    <div>
                        <div class="table-cell-primary">{{ $scheduledRestore->targetServer->name }}</div>
                        <div class="text-sm text-base-content/70">{{ $scheduledRestore->schema_name }}</div>
                    </div>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_backup_schedule', $scheduledRestore)
                @if($scheduledRestore->backupSchedule)
                    <div class="table-cell-primary">{{ $scheduledRestore->backupSchedule->name }}</div>
                    <div class="font-mono text-xs text-base-content/60">{{ $scheduledRestore->backupSchedule->expression }}</div>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_last_run', $scheduledRestore)
                @if($scheduledRestore->last_executed_at)
                    <div class="text-sm">{{ $scheduledRestore->last_executed_at->diffForHumans() }}</div>
                    @if($scheduledRestore->last_skip_reason)
                        <div class="text-xs text-warning">{{ __('Skipped: :reason', ['reason' => __($scheduledRestore->last_skip_reason)]) }}</div>
                    @elseif($scheduledRestore->lastRestore?->job)
                        @php $status = $scheduledRestore->lastRestore->job->status; @endphp
                        @if($status === 'completed')
                            <div class="text-xs text-success">{{ __('Completed') }}</div>
                        @elseif($status === 'failed')
                            <div class="text-xs text-error">{{ __('Failed') }}</div>
                        @elseif($status === 'running')
                            <div class="text-xs text-warning">{{ __('Running') }}</div>
                        @else
                            <div class="text-xs text-info">{{ __('Pending') }}</div>
                        @endif
                    @endif
                @else
                    <span class="text-base-content/50">{{ __('Never') }}</span>
                @endif
            @endscope

            @scope('cell_enabled', $scheduledRestore)
                @can('update', $scheduledRestore)
                    <x-button
                        :icon="$scheduledRestore->enabled ? 'o-check-circle' : 'o-pause-circle'"
                        wire:click="toggleEnabled('{{ $scheduledRestore->id }}')"
                        class="btn-ghost btn-sm {{ $scheduledRestore->enabled ? 'text-success' : 'text-base-content/50' }}"
                        :tooltip="$scheduledRestore->enabled ? __('Disable') : __('Enable')"
                    />
                @else
                    @if($scheduledRestore->enabled)
                        <x-badge value="{{ __('Enabled') }}" class="badge-success" />
                    @else
                        <x-badge value="{{ __('Disabled') }}" class="badge-ghost" />
                    @endif
                @endcan
            @endscope

            @scope('actions', $scheduledRestore)
                <div class="flex gap-2 justify-end">
                    @can('run', $scheduledRestore)
                        <x-button
                            icon="o-play"
                            wire:click="runNow('{{ $scheduledRestore->id }}')"
                            :tooltip="__('Run now')"
                            class="btn-ghost btn-sm"
                        />
                    @endcan
                    @can('update', $scheduledRestore)
                        <x-button
                            icon="o-pencil"
                            wire:click="openEdit('{{ $scheduledRestore->id }}')"
                            :tooltip="__('Edit')"
                            class="btn-ghost btn-sm"
                        />
                    @endcan
                    @can('delete', $scheduledRestore)
                        <x-button
                            icon="o-trash"
                            wire:click="confirmDelete('{{ $scheduledRestore->id }}')"
                            :tooltip="__('Delete')"
                            class="btn-ghost btn-sm text-error"
                        />
                    @endcan
                </div>
            @endscope
        </x-table>
    </x-card>

    <x-delete-confirmation-modal
        :title="__('Delete Scheduled Restore')"
        :message="__('Are you sure you want to delete this scheduled restore?')"
        onConfirm="deleteScheduledRestore"
    />

    <livewire:scheduled-restore.modal />
</div>
