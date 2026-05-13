<div wire:poll.5s>
    @if($errorMessage)
        <x-alert title="{{ $errorMessage }}" class="alert-error mb-4" icon="o-x-circle" />
    @endif

    <x-header :title="__('Snapshots')" separator progress-indicator>
        <x-slot:actions>
            <div class="hidden lg:flex items-center gap-2">
                @include('livewire.snapshot._filters', ['variant' => 'desktop'])
            </div>
        </x-slot:actions>
    </x-header>

    <div class="lg:hidden mb-4" x-data="{ showFilters: false }">
        @include('livewire.snapshot._filters', ['variant' => 'mobile'])
    </div>

    <x-card shadow>
        <x-table :headers="$headers" :rows="$snapshots" :sort-by="$sortBy" with-pagination
            :row-decoration="['bg-warning/5' => fn($snapshot) => !$snapshot->file_exists && $snapshot->job?->status === 'completed']"
        >
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($search || $statusFilter !== '' || $serverFilter !== '' || $dbTypeFilter !== '' || $fileMissing !== '')
                        {{ __('No snapshots found matching your filters.') }}
                    @else
                        {{ __('No snapshots yet. Backups will appear here.') }}
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_created_at', $snapshot)
                <div class="table-cell-primary">{{ \App\Support\Formatters::humanDate($snapshot->created_at) }}</div>
                <div class="text-sm text-base-content/70">{{ $snapshot->created_at->diffForHumans() }}</div>
            @endscope

            @scope('cell_server', $snapshot)
                @if($snapshot->databaseServer)
                    <div class="flex items-center gap-2">
                        <x-icon :name="$snapshot->database_type->icon()" class="w-5 h-5" />
                        <div>
                            <div class="table-cell-primary">{{ $snapshot->databaseServer->name }}</div>
                            <div class="text-sm text-base-content/70">{{ $snapshot->database_name }}</div>
                        </div>
                    </div>
                @else
                    <span class="text-base-content/50">{{ __('Loading...') }}</span>
                @endif
            @endscope

            @scope('cell_status', $snapshot)
                @php $status = $snapshot->job?->status ?? 'unknown'; @endphp
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
                @if(!$snapshot->file_exists && $status === 'completed')
                    <x-popover>
                        <x-slot:trigger>
                            <div class="flex items-center gap-1 text-warning text-xs mt-1">
                                <x-icon name="o-exclamation-triangle" class="w-3.5 h-3.5" />
                                {{ __('File missing') }}
                            </div>
                        </x-slot:trigger>
                        <x-slot:content>
                            <div class="text-xs space-y-1">
                                <div class="font-semibold text-warning">{{ __('Backup file not found on volume') }}</div>
                                @if($snapshot->file_verified_at)
                                    <div class="text-base-content/70">
                                        {{ __('Checked') }}: {{ \App\Support\Formatters::humanDate($snapshot->file_verified_at) }}
                                        ({{ $snapshot->file_verified_at->diffForHumans() }})
                                    </div>
                                @endif
                            </div>
                        </x-slot:content>
                    </x-popover>
                @endif
            @endscope

            @scope('cell_duration_ms', $snapshot)
                @php $job = $snapshot->job; @endphp
                @if($job?->status === 'running' && $job->started_at)
                    <span class="font-mono text-sm text-warning">{{ $job->started_at->diffForHumans(null, true) }}</span>
                @elseif($job?->getHumanDuration())
                    <span class="font-mono text-sm">{{ $job->getHumanDuration() }}</span>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_file_size', $snapshot)
                @if($snapshot->job?->status === 'completed')
                    <span class="font-mono text-sm">{{ $snapshot->getHumanFileSize() }}</span>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('actions', $snapshot)
                @php
                    $status = $snapshot->job?->status;
                    $canRestore = $status === 'completed'
                        && $snapshot->file_exists
                        && $snapshot->database_type !== \App\Enums\DatabaseType::REDIS;
                @endphp
                <div class="flex gap-2 justify-end">
                    @if($canRestore)
                        @can('restoreFrom', $snapshot)
                            <x-button
                                icon="o-arrow-uturn-left"
                                wire:click="triggerRestore('{{ $snapshot->id }}')"
                                :tooltip="__('Restore')"
                                class="btn-ghost btn-sm text-primary"
                            />
                        @endcan
                    @endif
                    @if($status === 'completed')
                        @can('download', $snapshot)
                            <x-button
                                icon="o-arrow-down-tray"
                                :link="route('snapshots.download', $snapshot)"
                                external
                                :tooltip="__('Download')"
                                class="btn-ghost btn-sm text-info"
                            />
                        @endcan
                    @endif
                    <x-button
                        icon="o-document-text"
                        wire:click="viewLogs('{{ $snapshot->job?->id }}')"
                        :tooltip="__('View Logs')"
                        class="btn-ghost btn-sm"
                        :class="empty($snapshot->job?->logs) ? 'opacity-30' : ''"
                        :disabled="empty($snapshot->job?->logs)"
                    />
                    @if(in_array($status, ['completed', 'failed']))
                        @can('delete', $snapshot)
                            <x-button
                                icon="o-trash"
                                wire:click="confirmDeleteSnapshot('{{ $snapshot->id }}')"
                                :tooltip="__('Delete')"
                                class="btn-ghost btn-sm text-error"
                            />
                        @endcan
                    @endif
                    @if($status === 'pending' && $snapshot->job)
                        @can('delete', $snapshot->job)
                            <x-button
                                icon="o-x-mark"
                                wire:click="confirmCancelJob('{{ $snapshot->job->id }}')"
                                :tooltip="__('Cancel')"
                                class="btn-ghost btn-sm text-error"
                            />
                        @endcan
                    @endif
                </div>
            @endscope
        </x-table>
    </x-card>

    @include('partials.job-logs-modal')

    @if($cancelJobId)
        <x-delete-confirmation-modal
            :title="__('Cancel Job')"
            :message="__('Are you sure you want to cancel this pending job?')"
            onConfirm="deletePendingJob"
        />
    @else
        <x-delete-confirmation-modal
            :title="__('Delete Snapshot')"
            :message="__('Are you sure you want to delete this snapshot? The backup file will be permanently removed.')"
            onConfirm="deleteSnapshot"
            :showKeepFiles="true"
        />
    @endif

    <livewire:restore.modal />
</div>
