<div>
    <!-- HEADER -->
    <x-header title="{{ __('Jobs') }}" separator progress-indicator>
        <x-slot:middle class="!justify-end">
            <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" clearable icon="o-magnifying-glass" />
        </x-slot:middle>
        <x-slot:actions>
            <x-button label="{{ __('Filters') }}" @click="$wire.drawer = true" responsive icon="o-funnel" class="btn-ghost" />
        </x-slot:actions>
    </x-header>

    <!-- TABLE -->
    <x-card shadow>
        <x-table :headers="$headers" :rows="$jobs" :sort-by="$sortBy" with-pagination>
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($search || $statusFilter !== 'all' || $typeFilter !== 'all')
                        {{ __('No jobs found matching your filters.') }}
                    @else
                        {{ __('No jobs yet. Backups and restores will appear here.') }}
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_type', $job)
                @if($job->snapshot)
                    <x-badge value="{{ __('Backup') }}" class="badge-primary" />
                @elseif($job->restore)
                    <x-badge value="{{ __('Restore') }}" class="badge-secondary" />
                @else
                    <x-badge value="{{ __('Unknown') }}" class="badge-ghost" />
                @endif
            @endscope

            @scope('cell_created_at', $job)
                <div class="table-cell-primary">{{ \App\Support\Formatters::humanDate($job->created_at) }}</div>
                <div class="text-sm text-base-content/70">{{ $job->created_at->diffForHumans() }}</div>
            @endscope

            @scope('cell_server', $job)
                @if($job->snapshot && $job->snapshot->databaseServer)
                    <div class="flex items-center gap-2">
                        <x-database-type-icon :type="$job->snapshot->database_type" />
                        <div>
                            <div class="table-cell-primary">{{ $job->snapshot->databaseServer->name }}</div>
                            <div class="text-sm text-base-content/70">{{ $job->snapshot->database_name }}</div>
                        </div>
                    </div>
                @elseif($job->restore && $job->restore->targetServer)
                    <div class="flex items-center gap-2">
                        @if($job->restore->snapshot)
                            <x-database-type-icon :type="$job->restore->snapshot->database_type" />
                        @endif
                        <div>
                            <div class="table-cell-primary">{{ $job->restore->targetServer->name }} (Target)</div>
                            <div class="text-sm text-base-content/70">{{ $job->restore->schema_name }}</div>
                            @if($job->restore->snapshot && $job->restore->snapshot->databaseServer)
                                <div class="text-xs text-base-content/50">From: {{ $job->restore->snapshot->databaseServer->name }}</div>
                            @endif
                        </div>
                    </div>
                @else
                    <span class="text-base-content/50">{{ __('Loading...') }}</span>
                @endif
            @endscope

            @scope('cell_status', $job)
                @if($job->status === 'completed')
                    <x-badge value="{{ __('Completed') }}" class="badge-success" />
                @elseif($job->status === 'failed')
                    <x-badge value="{{ __('Failed') }}" class="badge-error" />
                @elseif($job->status === 'running')
                    <x-badge value="{{ __('Running') }}" class="badge-warning" />
                @elseif($job->status === 'queued')
                    <x-badge value="{{ __('Queued') }}" class="badge-info" />
                @else
                    <x-badge value="{{ __('Pending') }}" class="badge-info" />
                @endif

                @if($job->status === 'failed' && $job->error_message)
                    <div class="text-xs text-error mt-1" title="{{ $job->error_message }}">
                        {{ Str::limit($job->error_message, 50) }}
                    </div>
                @endif
            @endscope

            @scope('cell_info', $job)
                @if($job->getHumanDuration())
                    <div>{{ $job->getHumanDuration() }}</div>
                @endif
                @if($job->snapshot && $job->status === 'completed')
                    <div class="text-sm text-base-content/70">{{ $job->snapshot->getHumanFileSize() }}</div>
                @elseif(!$job->getHumanDuration())
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('actions', $job)
                <div class="flex gap-2 justify-end">
                    @if($job->snapshot && $job->status === 'completed')
                        @can('download', $job->snapshot)
                            <x-button
                                icon="o-arrow-down-tray"
                                wire:click="download('{{ $job->snapshot->id }}')"
                                spinner
                                tooltip="{{ __('Download') }}"
                                class="btn-ghost btn-sm text-info"
                            />
                        @endcan
                    @endif
                    <x-button
                        icon="o-document-text"
                        wire:click="viewLogs('{{ $job->id }}')"
                        tooltip="{{ __('View Logs') }}"
                        class="btn-ghost btn-sm"
                        :class="empty($job->logs) ? 'opacity-30' : ''"
                        :disabled="empty($job->logs)"
                    />
                    @if($job->snapshot)
                        @can('delete', $job->snapshot)
                            <x-button
                                icon="o-trash"
                                wire:click="confirmDeleteSnapshot('{{ $job->snapshot->id }}')"
                                tooltip="{{ __('Delete') }}"
                                class="btn-ghost btn-sm text-error"
                            />
                        @endcan
                    @endif
                </div>
            @endscope
        </x-table>
    </x-card>

    <!-- FILTER DRAWER -->
    <x-drawer wire:model="drawer" title="{{ __('Filters') }}" right separator with-close-button class="lg:w-1/3">
        <div class="grid gap-5">
            <x-input placeholder="{{ __('Search...') }}" wire:model.live.debounce="search" icon="o-magnifying-glass" @keydown.enter="$wire.drawer = false" />
            <x-select
                placeholder="{{ __('Filter by type') }}"
                wire:model.live="typeFilter"
                :options="$typeOptions"
                icon="o-folder"
            />
            <x-select
                placeholder="{{ __('Filter by status') }}"
                wire:model.live="statusFilter"
                :options="$statusOptions"
                icon="o-funnel"
            />
        </div>

        <x-slot:actions>
            <x-button label="{{ __('Reset') }}" icon="o-x-mark" wire:click="clear" spinner />
            <x-button label="{{ __('Done') }}" icon="o-check" class="btn-primary" @click="$wire.drawer = false" />
        </x-slot:actions>
    </x-drawer>

    <!-- LOGS MODAL -->
    @include('livewire.backup-job._logs-modal')

    <!-- DELETE CONFIRMATION MODAL -->
    <x-modal wire:model="showDeleteModal" title="{{ __('Delete Snapshot') }}" separator>
        <div class="py-4">
            {{ __('Are you sure you want to delete this snapshot? The backup file will be permanently removed.') }}
        </div>
        <x-slot:actions>
            <x-button label="{{ __('Cancel') }}" @click="$wire.showDeleteModal = false" />
            <x-button label="{{ __('Delete') }}" class="btn-error" wire:click="deleteSnapshot" spinner />
        </x-slot:actions>
    </x-modal>
</div>
