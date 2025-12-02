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
                <div class="table-cell-primary">{{ $job->created_at->format('M d, Y H:i') }}</div>
                <div class="text-sm text-base-content/70">{{ $job->created_at->diffForHumans() }}</div>
            @endscope

            @scope('cell_server', $job)
                @if($job->snapshot && $job->snapshot->databaseServer)
                    <div class="table-cell-primary">{{ $job->snapshot->databaseServer->name }}</div>
                    <div class="text-sm text-base-content/70">
                        <x-badge :value="$job->snapshot->database_type" class="badge-xs" />
                        {{ $job->snapshot->database_name }}
                    </div>
                @elseif($job->restore && $job->restore->targetServer)
                    <div class="table-cell-primary">{{ $job->restore->targetServer->name }} (Target)</div>
                    <div class="text-sm text-base-content/70">
                        @if($job->restore->snapshot)
                            <x-badge :value="$job->restore->snapshot->database_type" class="badge-xs" />
                        @endif
                        {{ $job->restore->schema_name }}
                    </div>
                    @if($job->restore->snapshot && $job->restore->snapshot->databaseServer)
                        <div class="text-xs text-base-content/50">From: {{ $job->restore->snapshot->databaseServer->name }}</div>
                    @endif
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

            @scope('cell_duration', $job)
                @if($job->getHumanDuration())
                    {{ $job->getHumanDuration() }}
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_triggered_by', $job)
                @php
                    $triggeredBy = $job->snapshot ? $job->snapshot->triggeredBy : $job->restore?->triggeredBy;
                @endphp
                @if($triggeredBy)
                    <div class="flex items-center gap-2">
                        <x-icon name="o-user" class="w-4 h-4 text-base-content/50" />
                        {{ $triggeredBy->name }}
                    </div>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('actions', $job)
                <div class="flex gap-2 justify-end">
                    <x-button
                        icon="o-document-text"
                        wire:click="viewLogs('{{ $job->id }}')"
                        tooltip="{{ __('View Logs') }}"
                        class="btn-ghost btn-sm"
                        :class="empty($job->logs) ? 'opacity-30' : ''"
                        :disabled="empty($job->logs)"
                    />
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
</div>
