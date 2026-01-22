<div wire:poll.5s>
    <!-- HEADER with filters (Desktop) -->
    <x-header title="{{ __('Jobs') }}" separator progress-indicator>
        <x-slot:actions>
            <div class="hidden lg:flex items-center gap-2">
                <x-input
                    placeholder="{{ __('Search...') }}"
                    wire:model.live.debounce="search"
                    clearable
                    icon="o-magnifying-glass"
                    class="!input-sm w-48"
                />
                <x-select
                    placeholder="{{ __('All Types') }}"
                    placeholder-value=""
                    wire:model.live="typeFilter"
                    :options="$typeOptions"
                    class="!select-sm w-32"
                />
                <x-select
                    placeholder="{{ __('All Servers') }}"
                    placeholder-value=""
                    wire:model.live="serverFilter"
                    :options="$serverOptions"
                    class="!select-sm w-36"
                />
                <x-select
                    placeholder="{{ __('All Status') }}"
                    placeholder-value=""
                    wire:model.live="statusFilter"
                    :options="$statusOptions"
                    class="!select-sm w-32"
                />
                @if($search || $statusFilter !== '' || $typeFilter !== '' || $serverFilter !== '')
                    <x-button
                        icon="o-x-mark"
                        wire:click="clear"
                        spinner
                        class="btn-ghost btn-sm"
                        tooltip="{{ __('Clear filters') }}"
                    />
                @endif
            </div>
        </x-slot:actions>
    </x-header>

    <!-- FILTERS (Tablet & Mobile) -->
    <div class="lg:hidden mb-4 space-y-3">
        <!-- Tablet: search + inline dropdowns -->
        <div class="hidden sm:flex flex-wrap items-center gap-2">
            <x-input
                placeholder="{{ __('Search...') }}"
                wire:model.live.debounce="search"
                clearable
                icon="o-magnifying-glass"
                class="w-48"
            />
            <x-select
                placeholder="{{ __('All Types') }}"
                placeholder-value=""
                wire:model.live="typeFilter"
                :options="$typeOptions"
                class="!select-sm w-32"
            />
            <x-select
                placeholder="{{ __('All Servers') }}"
                placeholder-value=""
                wire:model.live="serverFilter"
                :options="$serverOptions"
                class="!select-sm w-36"
            />
            <x-select
                placeholder="{{ __('All Status') }}"
                placeholder-value=""
                wire:model.live="statusFilter"
                :options="$statusOptions"
                class="!select-sm w-32"
            />
            @if($search || $statusFilter !== '' || $typeFilter !== '' || $serverFilter !== '')
                <x-button
                    icon="o-x-mark"
                    wire:click="clear"
                    spinner
                    class="btn-ghost btn-sm"
                    tooltip="{{ __('Clear filters') }}"
                />
            @endif
        </div>
        <!-- Mobile: search + collapsible filters -->
        <div x-data="{ showFilters: false }" class="sm:hidden space-y-3">
            <x-input
                placeholder="{{ __('Search...') }}"
                wire:model.live.debounce="search"
                clearable
                icon="o-magnifying-glass"
            />
            <x-button
                label="{{ __('Filters') }}"
                icon="o-funnel"
                @click="showFilters = !showFilters"
                class="btn-ghost btn-sm w-full justify-start"
                ::class="showFilters && 'btn-active'"
            />
            <div x-show="showFilters" x-collapse class="mt-3 space-y-3">
                <x-select
                    label="{{ __('Type') }}"
                    placeholder="{{ __('All Types') }}"
                    placeholder-value=""
                    wire:model.live="typeFilter"
                    :options="$typeOptions"
                />
                <x-select
                    label="{{ __('Server') }}"
                    placeholder="{{ __('All Servers') }}"
                    placeholder-value=""
                    wire:model.live="serverFilter"
                    :options="$serverOptions"
                />
                <x-select
                    label="{{ __('Status') }}"
                    placeholder="{{ __('All Status') }}"
                    placeholder-value=""
                    wire:model.live="statusFilter"
                    :options="$statusOptions"
                />
                @if($search || $statusFilter !== '' || $typeFilter !== '' || $serverFilter !== '')
                    <x-button
                        label="{{ __('Clear filters') }}"
                        icon="o-x-mark"
                        wire:click="clear"
                        spinner
                        class="btn-ghost btn-sm"
                    />
                @endif
            </div>
        </div>
    </div>

    <!-- TABLE -->
    <x-card shadow>
        <x-table :headers="$headers" :rows="$jobs" :sort-by="$sortBy" with-pagination>
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($search || $statusFilter !== '' || $typeFilter !== '' || $serverFilter !== '')
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
                    <div class="badge badge-warning gap-1">
                        <x-loading class="loading-spinner loading-xs" />
                        {{ __('Running') }}
                    </div>
                @else
                    <x-badge value="{{ __('Pending') }}" class="badge-info" />
                @endif

            @endscope

            @scope('cell_duration_ms', $job)
                @if($job->status === 'running' && $job->started_at)
                    <span class="font-mono text-sm text-warning">{{ $job->started_at->diffForHumans(null, true) }}</span>
                @elseif($job->getHumanDuration())
                    <span class="font-mono text-sm">{{ $job->getHumanDuration() }}</span>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_snapshot_size', $job)
                @if($job->snapshot && $job->status === 'completed')
                    <span class="font-mono text-sm">{{ $job->snapshot->getHumanFileSize() }}</span>
                @else
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
