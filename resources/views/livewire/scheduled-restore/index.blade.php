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
                @php $enabled = $scheduledRestore->enabled; @endphp
                <div class="flex items-center gap-2">
                    <span class="tooltip" data-tip="{{ $enabled ? __('Enabled') : __('Disabled') }}">
                        <x-badge
                            value=""
                            :icon="$enabled ? 'o-check-circle' : 'o-pause-circle'"
                            class="badge-sm {{ $enabled ? 'badge-success' : 'badge-ghost' }}"
                        />
                    </span>
                    <div class="table-cell-primary">{{ $scheduledRestore->name }}</div>
                </div>
            @endscope

            @scope('cell_flow', $scheduledRestore)
                @php $source = $scheduledRestore->sourceServer; $target = $scheduledRestore->targetServer; @endphp
                <div class="flex items-center gap-3 min-w-0">
                    {{-- Source --}}
                    <div class="flex items-center gap-2 min-w-0 flex-1">
                        @if($source)
                            <x-icon :name="$source->database_type->icon()" class="w-5 h-5 shrink-0" />
                            <div class="min-w-0">
                                <div class="table-cell-primary truncate">{{ $scheduledRestore->source_database_name ?? __('(any database)') }}</div>
                                <a href="{{ route('database-servers.show', $source) }}" wire:navigate
                                   class="text-xs text-base-content/60 hover:text-primary hover:underline truncate block">
                                    {{ $source->name }}
                                </a>
                            </div>
                        @else
                            <span class="text-sm text-base-content/50 italic">{{ __('(source deleted)') }}</span>
                        @endif
                    </div>

                    <x-icon name="o-arrow-right" class="w-4 h-4 text-base-content/40 shrink-0" />

                    {{-- Target --}}
                    <div class="flex items-center gap-2 min-w-0 flex-1">
                        @if($target)
                            <x-icon :name="$target->database_type->icon()" class="w-5 h-5 shrink-0" />
                            <div class="min-w-0">
                                <div class="table-cell-primary truncate">{{ $scheduledRestore->schema_name }}</div>
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
            @endscope

            @scope('cell_backup_schedule', $scheduledRestore)
                @if($scheduledRestore->backupSchedule)
                    <div class="table-cell-primary">{{ $scheduledRestore->backupSchedule->name }}</div>
                    <div class="font-mono text-xs text-base-content/60">{{ $scheduledRestore->backupSchedule->expression }}</div>
                    <div class="text-xs text-base-content/50">{{ $scheduledRestore->backupSchedule->cronTranslation() }}</div>
                @else
                    <span class="text-base-content/50">-</span>
                @endif
            @endscope

            @scope('cell_last_run', $scheduledRestore)
                @if(! $scheduledRestore->last_executed_at)
                    <div class="flex items-center gap-2 text-base-content/50">
                        <x-icon name="o-clock" class="w-4 h-4" />
                        <span class="text-sm">{{ __('Never run') }}</span>
                    </div>
                @else
                    @php
                        $skipped = (bool) $scheduledRestore->last_skip_reason;
                        $verdictStatus = $skipped ? 'skipped' : ($scheduledRestore->lastRestore?->job?->status?->value ?? 'pending');
                    @endphp

                    <div class="flex flex-col gap-1">
                        <x-job-status-indicator :status="$verdictStatus" />
                        <div class="text-xs text-base-content/60">{{ $scheduledRestore->last_executed_at->diffForHumans() }}</div>
                        @if($skipped)
                            <div class="text-xs text-base-content/70">
                                <span class="font-medium">{{ __('Reason:') }}</span> {{ __($scheduledRestore->last_skip_reason) }}
                            </div>
                        @elseif($scheduledRestore->lastRestore)
                            <a
                                href="{{ route('restores.index', ['search' => $scheduledRestore->lastRestore->id]) }}"
                                wire:navigate
                                class="tooltip w-fit"
                                data-tip="{{ __('View restore') }}"
                            >
                                <kbd class="kbd kbd-xs font-mono cursor-pointer hover:text-primary">#{{ \Illuminate\Support\Str::substr($scheduledRestore->lastRestore->id, -7) }}</kbd>
                            </a>
                        @endif
                    </div>
                @endif
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
