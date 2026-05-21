<div wire:poll.30s>
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
        <x-table
            :headers="$headers"
            :rows="$snapshots"
            :sort-by="$sortBy"
            with-pagination
            :row-decoration="[
                'bg-error/5' => fn ($snapshot) => $snapshot->job?->status === 'failed',
                'bg-warning/5' => fn ($snapshot) => $snapshot->job?->status === 'running'
                    || (! $snapshot->file_exists && $snapshot->job?->status === 'completed'),
            ]"
        >
            <x-slot:empty>
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <x-icon name="o-archive-box" class="w-10 h-10 text-base-content/30 mb-3" />
                    @if($search || $statusFilter !== '' || $serverFilter !== '' || $dbTypeFilter !== '' || $fileMissing !== '')
                        <p class="font-medium">{{ __('No snapshots match your filters') }}</p>
                        <p class="text-sm text-base-content/60 mt-1">{{ __('Try clearing some filters to see more results.') }}</p>
                    @else
                        <p class="font-medium">{{ __('No snapshots yet') }}</p>
                        <p class="text-sm text-base-content/60 mt-1">{{ __('Snapshots will appear here once your first backup completes.') }}</p>
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_subject', $snapshot)
                @php $fileMissing = $snapshot->job?->status === 'completed' && ! $snapshot->file_exists; @endphp
                <div class="flex items-center gap-3 min-w-0">
                    <x-icon :name="$snapshot->database_type->icon()" class="w-6 h-6 shrink-0" />
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="table-cell-primary truncate">{{ $snapshot->database_name }}</span>
                            <a href="{{ route('database-servers.show', $snapshot->databaseServer) }}" wire:navigate
                               class="text-sm text-base-content/60 hover:text-primary hover:underline truncate">
                                {{ $snapshot->databaseServer->name }}
                            </a>
                        </div>
                        <div class="flex items-center gap-2 mt-1 flex-wrap">
                            <x-id-popover :id="$snapshot->id" />
                            @if($fileMissing)
                                <x-popover>
                                    <x-slot:trigger>
                                        <span class="badge badge-warning badge-soft badge-xs gap-1 cursor-help">
                                            <x-icon name="o-exclamation-triangle" class="w-3 h-3" />
                                            {{ __('File missing') }}
                                        </span>
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
                        </div>
                    </div>
                </div>
            @endscope

            @scope('cell_created_at', $snapshot)
                <div class="table-cell-primary">{{ $snapshot->created_at->diffForHumans() }}</div>
                <div class="text-sm text-base-content/60">{{ \App\Support\Formatters::humanDate($snapshot->created_at) }}</div>
            @endscope

            @scope('cell_status', $snapshot)
                @php $status = $snapshot->job?->status ?? 'pending'; $job = $snapshot->job; @endphp
                @if($status === 'completed')
                    <x-badge :value="__('Completed')" class="badge-success badge-soft badge-sm h-auto py-1 whitespace-normal text-center" />
                @elseif($status === 'failed')
                    <x-badge :value="__('Failed')" class="badge-error badge-soft badge-sm h-auto py-1 whitespace-normal text-center" />
                @elseif($status === 'running')
                    <x-badge class="badge-warning badge-soft badge-sm gap-1 h-auto py-1 whitespace-normal text-center">
                        <x-loading class="loading-spinner loading-xs shrink-0" />
                        {{ __('Running') }}
                    </x-badge>
                @else
                    <x-badge :value="__('Pending')" class="badge-info badge-soft badge-sm h-auto py-1 whitespace-normal text-center" />
                @endif

                @if($status === 'running' && $job?->started_at)
                    <div class="text-xs text-warning font-mono mt-1">{{ $job->started_at->diffForHumans(null, true) }}</div>
                @elseif($job?->getHumanDuration() || ($status === 'completed' && $snapshot->getHumanFileSize()))
                    <div class="flex items-center gap-3 text-xs text-base-content/60 mt-1">
                        @if($job?->getHumanDuration())
                            <span class="inline-flex items-center gap-1">
                                <x-icon name="o-clock" class="w-3 h-3" />
                                <span class="font-mono">{{ $job->getHumanDuration() }}</span>
                            </span>
                        @endif
                        @if($status === 'completed' && $snapshot->getHumanFileSize())
                            <span class="inline-flex items-center gap-1">
                                <x-icon name="o-archive-box" class="w-3 h-3" />
                                <span class="font-mono">{{ $snapshot->getHumanFileSize() }}</span>
                            </span>
                        @endif
                    </div>
                @endif
            @endscope

            @scope('actions', $snapshot)
                @php
                    $status = $snapshot->job?->status;
                    $job = $snapshot->job;
                    $canRestore = $status === 'completed' && $snapshot->file_exists && $snapshot->database_type !== \App\Enums\DatabaseType::REDIS;
                    $canDownload = $status === 'completed';
                    $canDelete = in_array($status, ['completed', 'failed'], true);
                    $canCancel = $status === 'pending' && $job;
                    $hasLogs = ! empty($job?->logs);
                @endphp
                <div class="flex items-center gap-1 justify-end">
                    @if($canRestore)
                        @can('restoreFrom', $snapshot)
                            <x-button
                                icon="bi.database-fill-down"
                                wire:click="triggerRestore('{{ $snapshot->id }}')"
                                :tooltip="__('Restore')"
                                class="btn-ghost btn-sm text-success"
                            />
                        @endcan
                    @endif

                    @if($canDownload)
                        @can('download', $snapshot)
                            <x-button
                                icon="o-arrow-down-tray"
                                :link="route('snapshots.download', $snapshot)"
                                external
                                :tooltip="__('Download')"
                                class="btn-ghost btn-sm text-primary"
                            />
                        @endcan
                    @endif

                    <x-button
                        icon="o-document-text"
                        wire:click="viewLogs('{{ $job?->id }}')"
                        :tooltip="__('View Logs')"
                        class="btn-ghost btn-sm"
                        :class="$hasLogs ? '' : 'opacity-30'"
                        :disabled="! $hasLogs"
                    />

                    @if($canDelete)
                        @can('delete', $snapshot)
                            <x-button
                                icon="o-trash"
                                wire:click="confirmDeleteSnapshot('{{ $snapshot->id }}')"
                                :tooltip="__('Delete')"
                                class="btn-ghost btn-sm text-error"
                            />
                        @endcan
                    @endif

                    @if($canCancel)
                        @can('delete', $job)
                            <x-button
                                icon="o-x-mark"
                                wire:click="confirmCancelJob('{{ $job->id }}')"
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
