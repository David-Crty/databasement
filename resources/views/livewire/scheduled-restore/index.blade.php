<div>
    <x-header :title="__('Scheduled Restores')" separator progress-indicator>
        <x-slot:actions>
            <div class="hidden lg:flex items-center gap-2">
                @include('livewire.restore._filters', ['variant' => 'desktop', 'statusModel' => 'enabledFilter', 'statusOptions' => $enabledOptions, 'statusPlaceholder' => __('Status'), 'sourceOptions' => $serverOptions, 'targetOptions' => $serverOptions])
            </div>
            @can('create', \App\Models\ScheduledRestore::class)
                <x-button
                    :label="__('New Scheduled Restore')"
                    icon="o-plus"
                    wire:click="openCreate"
                    spinner
                    class="btn-primary btn-sm"
                />
            @endcan
        </x-slot:actions>
    </x-header>

    <div class="lg:hidden mb-4" x-data="{ showFilters: false }">
        @include('livewire.restore._filters', ['variant' => 'mobile', 'statusModel' => 'enabledFilter', 'statusOptions' => $enabledOptions, 'statusPlaceholder' => __('Status'), 'sourceOptions' => $serverOptions, 'targetOptions' => $serverOptions])
    </div>

    <x-card shadow>
        <x-table :headers="$headers" :rows="$scheduledRestores" :sort-by="$sortBy" with-pagination>
            <x-slot:empty>
                <div class="text-center text-base-content/50 py-8">
                    @if($this->hasFilters)
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
                <x-restore-flow>
                    <x-slot:source>
                        <x-server-database-label
                            :icon="$source?->database_type->icon()"
                            :label="$scheduledRestore->source_database_name ?? __('(any database)')"
                            :server="$source"
                            :deleted-label="__('(source deleted)')"
                        />
                    </x-slot:source>
                    <x-slot:target>
                        <x-server-database-label
                            :icon="$target?->database_type->icon()"
                            :label="$scheduledRestore->schema_name"
                            :server="$target"
                            :deleted-label="__('(target deleted)')"
                        />
                    </x-slot:target>
                </x-restore-flow>
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
                            spinner
                            :tooltip="__('Run now')"
                            class="btn-ghost btn-sm"
                        />
                    @endcan
                    @can('update', $scheduledRestore)
                        <x-button
                            icon="o-pencil"
                            wire:click="openEdit('{{ $scheduledRestore->id }}')"
                            spinner
                            :tooltip="__('Edit')"
                            class="btn-ghost btn-sm"
                        />
                    @endcan
                    @can('delete', $scheduledRestore)
                        <x-button
                            icon="o-trash"
                            wire:click="confirmDelete('{{ $scheduledRestore->id }}')"
                            spinner
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

    <x-confirm-modal
        model="showRunDisabledModal"
        :title="__('Run Disabled Scheduled Restore')"
        :message="__('This scheduled restore is disabled. Do you want to run it anyway?')"
        on-confirm="runDisabledNow"
        :confirm-label="__('Run anyway')"
        confirm-class="btn-primary"
    >
        <p class="text-sm text-base-content/60 mt-2">
            {{ __('It stays disabled afterwards and will not run automatically on its schedule.') }}
        </p>
    </x-confirm-modal>

    <livewire:scheduled-restore.modal />
</div>
