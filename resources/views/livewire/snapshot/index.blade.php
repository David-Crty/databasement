<div @if($editCommentSnapshotId === null) wire:poll.30s @endif>
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

    @php $canBulkDelete = auth()->user()->can('deleteAny', \App\Models\Snapshot::class); @endphp

    <x-card shadow>
        @if($canBulkDelete && count($selected) > 0)
            @php
                $total = $snapshots->total();
                $pageIds = $snapshots->pluck('id')->all();
                $pageFullySelected = $pageIds !== [] && array_diff($pageIds, $selected) === [];
            @endphp
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 mb-4 px-4 py-2 rounded-lg bg-base-200 text-sm">
                @if($selectAllMatching)
                    <span>{{ trans_choice('All :count matching snapshot is selected.|All :count matching snapshots are selected.', $total, ['count' => $total]) }}</span>
                @else
                    <span>{{ trans_choice(':count snapshot selected.|:count snapshots selected.', count($selected), ['count' => count($selected)]) }}</span>
                    @if($pageFullySelected && $total > count($pageIds))
                        <x-button
                            :label="trans_choice('Select all :count matching snapshot|Select all :count matching snapshots', $total, ['count' => $total])"
                            wire:click="$set('selectAllMatching', true)"
                            spinner="selectAllMatching"
                            class="btn-link btn-xs px-0"
                        />
                    @endif
                @endif
                <x-button :label="__('Clear selection')" wire:click="clearSelection" spinner class="btn-ghost btn-xs" />
                <x-button :label="__('Delete')" icon="o-trash" wire:click="confirmBulkDelete" spinner class="btn-error btn-xs ml-auto" />
            </div>
        @endif

        <x-table
            :headers="$headers"
            :rows="$snapshots"
            :sort-by="$sortBy"
            with-pagination
            :selectable="$canBulkDelete"
            wire:model.live="selected"
            x-init="$watch('selection', () => isSelectable && handleCheckAll())"
            :row-decoration="[
                'group' => fn () => true,
                'opacity-60' => fn ($snapshot) => $snapshot->deleting,
                'bg-error/5' => fn ($snapshot) => $snapshot->job?->status?->value === 'failed',
                'bg-warning/5' => fn ($snapshot) => $snapshot->job?->status?->value === 'running'
                    || $snapshot->hasMissingFile(),
            ]"
        >
            <x-slot:empty>
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <x-icon name="o-archive-box" class="w-10 h-10 text-base-content/30 mb-3" />
                    @if($this->hasFilters)
                        <p class="font-medium">{{ __('No snapshots match your filters') }}</p>
                        <p class="text-sm text-base-content/60 mt-1">{{ __('Try clearing some filters to see more results.') }}</p>
                    @else
                        <p class="font-medium">{{ __('No snapshots yet') }}</p>
                        <p class="text-sm text-base-content/60 mt-1">{{ __('Snapshots will appear here once your first backup completes.') }}</p>
                    @endif
                </div>
            </x-slot:empty>

            @scope('cell_subject', $snapshot)
                @php $hasMissingFile = $snapshot->hasMissingFile(); @endphp
                <div class="flex items-center gap-3 min-w-0">
                    <x-icon :name="$snapshot->database_type->icon()" class="w-6 h-6 shrink-0" />
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="table-cell-primary truncate">{{ $snapshot->database_name }}</span>
                        </div>
                        <div class="flex items-center gap-2 mt-1 flex-wrap">
                            <a href="{{ route('database-servers.show', $snapshot->databaseServer) }}" wire:navigate
                               class="badge badge-xs badge-ghost gap-1 hover:text-primary">
                                <x-icon name="o-server-stack" class="w-3 h-3" />
                                {{ $snapshot->databaseServer->name }}
                            </a>
                            @foreach($snapshot->files as $file)
                                @if($file->volume)
                                    <x-snapshot-volume-badge :file="$file" />
                                @endif
                            @endforeach
                            @if($hasMissingFile)
                                <x-popover>
                                    <x-slot:trigger>
                                        <span class="badge badge-warning badge-xs gap-1 cursor-help">
                                            <x-icon name="o-exclamation-triangle" class="w-3 h-3" />
                                            {{ __('File missing') }}
                                        </span>
                                    </x-slot:trigger>
                                    <x-slot:content>
                                        <div class="text-xs space-y-1">
                                            <div class="font-semibold text-warning">{{ __('Backup file not found on volume') }}</div>
                                            @php $lastVerifiedAt = $snapshot->lastVerifiedAt(); @endphp
                                            @if($lastVerifiedAt)
                                                <div class="text-base-content/70">
                                                    {{ __('Last checked') }}: {{ \App\Support\Formatters::humanDate($lastVerifiedAt) }}
                                                    ({{ $lastVerifiedAt->diffForHumans() }})
                                                </div>
                                            @endif
                                        </div>
                                    </x-slot:content>
                                </x-popover>
                            @endif
                            @unless($snapshot->deleting)
                                @include('livewire.snapshot._lock', ['snapshot' => $snapshot])
                            @endunless
                        </div>
                        @include('livewire.snapshot._comment', ['snapshot' => $snapshot])
                    </div>
                </div>
            @endscope

            @scope('cell_created_at', $snapshot)
                <div class="table-cell-primary">{{ $snapshot->created_at->diffForHumans() }}</div>
                <div class="text-sm text-base-content/60">{{ \App\Support\Formatters::humanDate($snapshot->created_at) }}</div>
            @endscope

            @scope('cell_status', $snapshot)
                <x-job-status-cell :job="$snapshot->job" :status="$snapshot->deleting ? 'deleting' : null" :file-size="$snapshot->getHumanFileSize()" />
            @endscope

            @scope('actions', $snapshot)
                @php
                    $status = $snapshot->deleting ? 'deleting' : $snapshot->job?->status?->value;
                    $job = $snapshot->job;
                    $canRestore = $status === 'completed' && $snapshot->hasExistingFile() && $snapshot->database_type !== \App\Enums\DatabaseType::REDIS;
                    $completedFiles = $snapshot->files->where('status', \App\Enums\SnapshotFileStatus::Completed);
                    $canDownload = $status === 'completed' && $completedFiles->where('file_exists', true)->isNotEmpty();
                    $canDelete = in_array($status, ['completed', 'failed', 'cancelled'], true);
                @endphp
                <div class="flex items-center gap-1 justify-end">
                    @if($canRestore)
                        @can('restoreFrom', $snapshot)
                            <x-button
                                icon="bi.database-fill-down"
                                wire:click="triggerRestore('{{ $snapshot->id }}')"
                                spinner
                                :tooltip="__('Restore')"
                                class="btn-ghost btn-sm text-success"
                            />
                        @endcan
                    @endif

                    @if($canDownload)
                        @can('download', $snapshot)
                            @if($completedFiles->count() > 1)
                                {{-- Stored on several volumes: open the copy picker --}}
                                <x-button
                                    icon="o-arrow-down-tray"
                                    wire:click="openDownloadModal('{{ $snapshot->id }}')"
                                    spinner
                                    :tooltip="__('Download')"
                                    class="btn-ghost btn-sm text-primary"
                                />
                            @else
                                <x-button
                                    icon="o-arrow-down-tray"
                                    :link="route('snapshots.download', $snapshot)"
                                    external
                                    :tooltip="__('Download')"
                                    class="btn-ghost btn-sm text-primary"
                                />
                            @endif
                        @endcan
                    @endif

                    @include('partials.view-logs-button', ['job' => $job])

                    @if($canDelete)
                        @can('delete', $snapshot)
                            <x-button
                                icon="o-trash"
                                wire:click="confirmDeleteSnapshot('{{ $snapshot->id }}')"
                                spinner
                                :tooltip="__('Delete')"
                                class="btn-ghost btn-sm text-error"
                            />
                        @endcan

                        @if($snapshot->locked)
                            @can(\App\Enums\Ability::DeleteSnapshots->value)
                                {{-- The title sits on the wrapper: a disabled button gets pointer-events: none --}}
                                <span class="cursor-not-allowed" title="{{ __('This snapshot is locked. Unlock it to delete.') }}">
                                    <x-button icon="o-trash" class="btn-ghost btn-sm opacity-30" disabled />
                                </span>
                            @endcan
                        @endif
                    @endif

                    @include('partials.cancel-job-button', ['job' => $job])
                </div>
            @endscope
        </x-table>
    </x-card>

    @include('partials.job-logs-modal')

    <x-modal wire:model="showDownloadModal" :title="__('Download Snapshot')" class="backdrop-blur">
        @if($this->downloadSnapshot)
            <p class="text-sm text-base-content/70">
                {{ __('This snapshot is stored on several volumes. Choose which copy to download.') }}
            </p>

            {{-- The same archive is uploaded to every volume, so the size is shown once. --}}
            @if($this->downloadSnapshot->file_size)
                <div class="mt-2 flex items-center gap-1 text-xs text-base-content/60">
                    <x-icon name="o-archive-box" class="w-3.5 h-3.5" />
                    <span class="font-mono">{{ $this->downloadSnapshot->getHumanFileSize() }}</span>
                </div>
            @endif

            <div class="mt-4 space-y-2">
                @foreach($this->downloadSnapshot->files->where('status', \App\Enums\SnapshotFileStatus::Completed) as $file)
                    @if($file->file_exists)
                        <a
                            href="{{ route('snapshots.download', [$this->downloadSnapshot, 'file' => $file->id]) }}"
                            target="_blank"
                            class="flex items-center justify-between gap-3 rounded-lg border border-base-300 px-4 py-3 hover:border-primary hover:bg-base-200/40 transition-colors"
                        >
                            <span class="flex items-center gap-2 min-w-0">
                                <x-icon name="o-server-stack" class="w-4 h-4 shrink-0 text-base-content/60" />
                                <span class="truncate font-medium">{{ $file->volume->name }}</span>
                                <span class="text-xs text-base-content/50">({{ $file->volume->type }})</span>
                            </span>
                            <x-icon name="o-arrow-down-tray" class="w-4 h-4 shrink-0 text-primary" />
                        </a>
                    @else
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-base-300 px-4 py-3 opacity-60">
                            <span class="flex items-center gap-2 min-w-0">
                                <x-icon name="o-server-stack" class="w-4 h-4 shrink-0 text-base-content/60" />
                                <span class="truncate font-medium">{{ $file->volume->name }}</span>
                                <span class="text-xs text-base-content/50">({{ $file->volume->type }})</span>
                            </span>
                            <span class="badge badge-warning badge-xs gap-1 shrink-0">
                                <x-icon name="o-exclamation-triangle" class="w-3 h-3" />
                                {{ __('File missing') }}
                            </span>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif

        <x-slot:actions>
            <x-button :label="__('Close')" @click="$wire.showDownloadModal = false" />
        </x-slot:actions>
    </x-modal>

    <x-delete-confirmation-modal
        :title="$deleteSnapshotId ? __('Delete Snapshot') : __('Delete Snapshots')"
        :message="$deleteSnapshotId
            ? __('Are you sure you want to delete this snapshot? The backup file will be permanently removed.')
            : trans_choice('Are you sure you want to delete :count snapshot? Its backup files will be permanently removed.|Are you sure you want to delete :count snapshots? Their backup files will be permanently removed.', $bulkDeleteCount, ['count' => $bulkDeleteCount])"
        :onConfirm="$deleteSnapshotId ? 'deleteSnapshot' : 'bulkDeleteSnapshots'"
        :showKeepFiles="true"
    >
        @if(! $deleteSnapshotId && $bulkSkipCount > 0)
            <x-alert icon="o-information-circle" class="alert-info mt-4">
                {{ trans_choice(':count locked or in-progress snapshot will be skipped.|:count locked or in-progress snapshots will be skipped.', $bulkSkipCount, ['count' => $bulkSkipCount]) }}
            </x-alert>
        @endif
    </x-delete-confirmation-modal>

    <livewire:restore.modal />
</div>
