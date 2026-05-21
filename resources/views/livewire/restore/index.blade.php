<div wire:poll.30s>
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
        <x-table
            :headers="$headers"
            :rows="$restores"
            :sort-by="$sortBy"
            with-pagination
            :row-decoration="[
                'bg-error/5' => fn ($restore) => $restore->job?->status === 'failed',
                'bg-warning/5' => fn ($restore) => $restore->job?->status === 'running',
            ]"
        >
            <x-slot:empty>
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <x-icon name="o-arrow-path" class="w-10 h-10 text-base-content/30 mb-3" />
                    @if($search || $statusFilter !== '' || $sourceServerFilter !== '' || $targetServerFilter !== '' || $dbTypeFilter !== '')
                        <p class="font-medium">{{ __('No restores match your filters') }}</p>
                        <p class="text-sm text-base-content/60 mt-1">{{ __('Try clearing some filters to see more results.') }}</p>
                    @else
                        <p class="font-medium">{{ __('No restores yet') }}</p>
                        <p class="text-sm text-base-content/60 mt-1">{{ __('Trigger one from a snapshot or via the “New Restore” button.') }}</p>
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_flow', $restore)
                @php $snapshot = $restore->snapshot; $target = $restore->targetServer; @endphp
                <div class="flex items-center gap-3 min-w-0">
                    {{-- Source --}}
                    <div class="flex items-center gap-2 min-w-0 flex-1">
                        @if($snapshot)
                            <x-icon :name="$snapshot->database_type->icon()" class="w-5 h-5 shrink-0" />
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <span class="table-cell-primary truncate">{{ $snapshot->database_name }}</span>
                                    <a
                                        href="{{ route('snapshots.index', ['search' => $snapshot->id]) }}"
                                        wire:navigate
                                        class="text-base-content/40 hover:text-primary tooltip shrink-0"
                                        data-tip="{{ __('View snapshot') }}"
                                    >
                                        <x-icon name="o-arrow-top-right-on-square" class="w-3.5 h-3.5" />
                                    </a>
                                </div>
                                <a href="{{ route('database-servers.show', $snapshot->databaseServer) }}" wire:navigate
                                   class="text-xs text-base-content/60 hover:text-primary hover:underline truncate block">
                                    {{ $snapshot->databaseServer->name }}
                                </a>
                            </div>
                        @else
                            <span class="text-sm text-base-content/50 italic">{{ __('(snapshot deleted)') }}</span>
                        @endif
                    </div>

                    <x-icon name="o-arrow-right" class="w-4 h-4 text-base-content/40 shrink-0" />

                    {{-- Target --}}
                    <div class="flex items-center gap-2 min-w-0 flex-1">
                        @if($target)
                            <x-icon :name="$snapshot?->database_type?->icon() ?? 'o-server'" class="w-5 h-5 shrink-0" />
                            <div class="min-w-0">
                                <div class="table-cell-primary truncate">{{ $restore->schema_name }}</div>
                                <a href="{{ route('database-servers.show', $target) }}" wire:navigate
                                   class="text-xs text-base-content/60 hover:text-primary hover:underline truncate block">
                                    {{ $target->name }}
                                </a>
                            </div>
                        @else
                            <span class="text-sm text-base-content/50 italic">{{ __('(target deleted)') }}</span>
                        @endif
                    </div>
                </div>

                <div class="mt-1.5">
                    <x-id-popover :id="$restore->id" />
                </div>
            @endscope

            @scope('cell_created_at', $restore)
                <div class="table-cell-primary">{{ $restore->created_at->diffForHumans() }}</div>
                <div class="text-sm text-base-content/60">{{ \App\Support\Formatters::humanDate($restore->created_at) }}</div>
                <div class="flex items-center gap-1 text-xs text-base-content/60 mt-1">
                    <x-icon name="o-user" class="w-3 h-3" />
                    <span>{{ $restore->triggeredBy?->name ?? __('system') }}</span>
                </div>
            @endscope

            @scope('cell_status', $restore)
                @php $status = $restore->job?->status ?? 'pending'; $job = $restore->job; @endphp
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
                @elseif($job?->getHumanDuration())
                    <div class="flex items-center gap-1 text-xs text-base-content/60 mt-1">
                        <x-icon name="o-clock" class="w-3 h-3" />
                        <span class="font-mono">{{ $job->getHumanDuration() }}</span>
                    </div>
                @endif
            @endscope

            @scope('actions', $restore)
                @php
                    $snapshot = $restore->snapshot;
                    $target = $restore->targetServer;
                    $canRerun = $snapshot && $target;
                    $job = $restore->job;
                    $hasLogs = ! empty($job?->logs);
                @endphp
                <div class="flex items-center gap-1 justify-end">
                    @if($canRerun)
                        @can('create', \App\Models\Restore::class)
                            <x-button
                                icon="o-arrow-path"
                                wire:click="rerunRestore('{{ $restore->id }}')"
                                spinner
                                :tooltip="__('Re-run')"
                                class="btn-ghost btn-sm text-success"
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
