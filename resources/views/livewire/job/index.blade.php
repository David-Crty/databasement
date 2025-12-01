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
                @else
                    <x-badge value="{{ __('Restore') }}" class="badge-secondary" />
                @endif
            @endscope

            @scope('cell_started_at', $job)
                @if($job->started_at)
                    <div class="table-cell-primary">{{ $job->started_at->format('M d, Y H:i') }}</div>
                    <div class="text-sm text-base-content/70">{{ $job->started_at->diffForHumans() }}</div>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_server', $job)
                @if($job->snapshot)
                    <div class="table-cell-primary">{{ $job->snapshot->databaseServer->name }}</div>
                    <div class="text-sm text-base-content/70">
                        <x-badge :value="$job->snapshot->database_type" class="badge-xs" />
                        {{ $job->snapshot->database_name }}
                    </div>
                @elseif($job->restore)
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
    <x-modal wire:model="showLogsModal" title="{{ __('Job Logs') }}" class="backdrop-blur" box-class="w-11/12 max-w-5xl max-h-[90vh]">
        @if($this->selectedJob)
            <div class="space-y-4">
                <!-- Job Info Header -->
                <div class="p-4 bg-base-200 rounded-lg space-y-2">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm text-base-content/70">
                                {{ $this->selectedJob->snapshot ? 'Backup' : 'Restore' }} Job
                            </div>
                            <div class="font-semibold">
                                @if($this->selectedJob->snapshot)
                                    {{ $this->selectedJob->snapshot->databaseServer->name }} / {{ $this->selectedJob->snapshot->database_name }}
                                @elseif($this->selectedJob->restore)
                                    {{ $this->selectedJob->restore->targetServer->name }} / {{ $this->selectedJob->restore->schema_name }}
                                @endif
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm text-base-content/70">{{ __('Status') }}</div>
                            @if($this->selectedJob->status === 'completed')
                                <x-badge value="{{ __('Completed') }}" class="badge-success" />
                            @elseif($this->selectedJob->status === 'failed')
                                <x-badge value="{{ __('Failed') }}" class="badge-error" />
                            @elseif($this->selectedJob->status === 'running')
                                <x-badge value="{{ __('Running') }}" class="badge-warning" />
                            @else
                                <x-badge value="{{ ucfirst($this->selectedJob->status) }}" class="badge-info" />
                            @endif
                        </div>
                    </div>
                    @if($this->selectedJob->started_at)
                        <div class="text-sm text-base-content/70">
                            Started: {{ $this->selectedJob->started_at->format('Y-m-d H:i:s') }}
                            @if($this->selectedJob->completed_at)
                                | Completed: {{ $this->selectedJob->completed_at->format('Y-m-d H:i:s') }}
                                | Duration: {{ $this->selectedJob->getHumanDuration() }}
                            @endif
                        </div>
                    @endif
                </div>

                <!-- Logs Container -->
                <div class="space-y-2 max-h-[60vh] overflow-y-auto">
                    @php
                        $logs = $this->selectedJob->getLogs();
                    @endphp

                    @forelse($logs as $index => $log)
                        @if($log['type'] === 'command')
                            <!-- Command Log Entry -->
                            <div class="p-4 bg-base-300 rounded-lg border-l-4 border-primary">
                                <div class="flex items-start justify-between mb-2">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-window class="w-5 h-5 text-primary" />
                                        <span class="font-semibold text-primary">Command</span>
                                    </div>
                                    <div class="text-xs text-base-content/50 font-mono">
                                        {{ \Carbon\Carbon::parse($log['timestamp'])->format('H:i:s.v') }}
                                    </div>
                                </div>
                                <div class="bg-base-100 p-3 rounded font-mono text-sm overflow-x-auto">
                                    <code class="text-success">$ {{ $log['command'] }}</code>
                                </div>
                                @if(isset($log['output']) && !empty(trim($log['output'])))
                                    <div class="mt-2">
                                        <div class="text-xs text-base-content/70 mb-1">Output:</div>
                                        <div class="bg-base-100 p-3 rounded font-mono text-xs overflow-x-auto max-h-48 overflow-y-auto">
                                            <pre class="text-base-content/80">{{ trim($log['output']) }}</pre>
                                        </div>
                                    </div>
                                @endif
                                @if(isset($log['exit_code']) && $log['exit_code'] !== null)
                                    <div class="mt-2 flex items-center gap-2">
                                        <span class="text-xs text-base-content/70">Exit code:</span>
                                        <x-badge
                                            :value="$log['exit_code']"
                                            :class="$log['exit_code'] === 0 ? 'badge-success' : 'badge-error'"
                                        />
                                    </div>
                                @endif
                            </div>
                        @else
                            <!-- Regular Log Entry -->
                            <div class="p-4 rounded-lg border-l-4
                                @if($log['level'] === 'error') bg-error/10 border-error
                                @elseif($log['level'] === 'warning') bg-warning/10 border-warning
                                @elseif($log['level'] === 'success') bg-success/10 border-success
                                @else bg-base-200 border-info
                                @endif
                            ">
                                <div class="flex items-start justify-between mb-2">
                                    <div class="flex items-center gap-2">
                                        @if($log['level'] === 'error')
                                            <x-icon name="o-x-circle" class="w-5 h-5 text-error" />
                                        @elseif($log['level'] === 'warning')
                                            <x-icon name="o-exclamation-triangle" class="w-5 h-5 text-warning" />
                                        @elseif($log['level'] === 'success')
                                            <x-icon name="o-check-circle" class="w-5 h-5 text-success" />
                                        @else
                                            <x-icon name="o-information-circle" class="w-5 h-5 text-info" />
                                        @endif
                                        <span class="font-semibold capitalize
                                            @if($log['level'] === 'error') text-error
                                            @elseif($log['level'] === 'warning') text-warning
                                            @elseif($log['level'] === 'success') text-success
                                            @else text-info
                                            @endif
                                        ">{{ $log['level'] }}</span>
                                    </div>
                                    <div class="text-xs text-base-content/50 font-mono">
                                        {{ \Carbon\Carbon::parse($log['timestamp'])->format('H:i:s.v') }}
                                    </div>
                                </div>
                                <div class="text-sm">{{ $log['message'] }}</div>
                                @if(isset($log['context']) && !empty($log['context']))
                                    <div class="mt-2 p-2 bg-base-100 rounded font-mono text-xs overflow-x-auto">
                                        <pre>{{ json_encode($log['context'], JSON_PRETTY_PRINT) }}</pre>
                                    </div>
                                @endif
                            </div>
                        @endif
                    @empty
                        <div class="text-center py-8 text-base-content/50">
                            <x-icon name="o-document-text" class="w-12 h-12 mx-auto mb-2 opacity-50" />
                            <div>No logs available for this job.</div>
                        </div>
                    @endforelse
                </div>
            </div>

            <x-slot:actions>
                <x-button label="{{ __('Close') }}" @click="$wire.showLogsModal = false" />
            </x-slot:actions>
        @endif
    </x-modal>
</div>
