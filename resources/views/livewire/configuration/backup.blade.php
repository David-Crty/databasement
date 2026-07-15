<div>
    <x-header :title="__('Configuration')" separator>
        <x-slot:subtitle>
            {{ __('Manage backup schedules and operation settings.') }}
        </x-slot:subtitle>
    </x-header>

    @include('livewire.configuration._tabs', ['active' => 'backup'])

    @if($showDeprecatedBackupEnv)
        <x-alert class="alert-warning mb-4" icon="o-exclamation-triangle" dismissible>
            {{ __('Deprecated BACKUP_* environment variables detected. Backup settings are now configured in the UI. You can safely remove BACKUP_* variables from your environment.') }}
        </x-alert>
    @endif

    <div class="grid gap-6">
        <!-- Backup Schedules -->
        <x-card shadow class="min-w-0">
            <x-card-heading :title="__('Backup Schedules')" :subtitle="__('Define cron schedules that database servers can use for automated backups.')">
                @if ($this->canManage)
                    <x-button
                        :label="__('Add Schedule')"
                        icon="o-plus"
                        class="btn-primary btn-sm"
                        wire:click="openScheduleModal"
                    />
                @endif
            </x-card-heading>

            <x-table :headers="$scheduleHeaders" :rows="$backupSchedules" show-empty-text :empty-text="__('No backup schedules defined.')">
                @scope('cell_name', $schedule)
                    <span class="font-medium">{{ $schedule->name }}</span>
                @endscope

                @scope('cell_expression', $schedule)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge badge-neutral shrink-0 whitespace-nowrap font-mono">
                            <x-icon name="o-calendar-days" class="w-3 h-3" />
                            {{ $schedule->expression }}
                        </span>
                        <span class="text-sm text-base-content/60 whitespace-nowrap">{{ \App\Support\Formatters::cronTranslation($schedule->expression) }}</span>
                    </div>
                @endscope

                @scope('cell_usage', $schedule)
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($schedule->backups_count > 0)
                            <x-popover>
                                <x-slot:trigger>
                                    <span class="badge badge-outline badge-info cursor-default whitespace-nowrap">
                                        <x-icon name="o-server-stack" class="w-3 h-3" />
                                        {{ trans_choice(':count server|:count servers', $schedule->backups_count) }}
                                    </span>
                                </x-slot:trigger>
                                <x-slot:content class="max-w-64">
                                    {{ \App\Support\Formatters::truncatedList($schedule->backups->pluck('databaseServer.name')) }}
                                </x-slot:content>
                            </x-popover>
                        @endif
                        @if ($schedule->scheduled_restores_count > 0)
                            <x-popover>
                                <x-slot:trigger>
                                    <span class="badge badge-outline badge-info cursor-default whitespace-nowrap">
                                        <x-icon name="o-calendar" class="w-3 h-3" />
                                        {{ trans_choice(':count scheduled restore|:count scheduled restores', $schedule->scheduled_restores_count) }}
                                    </span>
                                </x-slot:trigger>
                                <x-slot:content class="max-w-64">
                                    {{ \App\Support\Formatters::truncatedList($schedule->scheduledRestores->pluck('name')) }}
                                </x-slot:content>
                            </x-popover>
                        @endif
                    </div>
                @endscope

                @scope('cell_actions', $schedule)
                    @if ($this->canManage)
                        <div class="flex justify-end flex-nowrap gap-1">
                            <x-button icon="o-pencil-square" class="btn-ghost btn-xs tooltip tooltip-left" wire:click="openScheduleModal('{{ $schedule->id }}')" :tooltip-left="__('Edit')" />
                            @if ($schedule->total_backups_count > 0 || $schedule->scheduled_restores_count > 0)
                                <x-popover>
                                    <x-slot:trigger>
                                        <x-button icon="o-trash" class="btn-ghost btn-xs opacity-40" disabled />
                                    </x-slot:trigger>
                                    <x-slot:content>
                                        @if ($schedule->total_backups_count > 0 && $schedule->scheduled_restores_count > 0)
                                            {{ __('In use by servers and scheduled restores') }}
                                        @elseif ($schedule->total_backups_count > 0)
                                            {{ __('In use by servers') }}
                                        @else
                                            {{ __('In use by scheduled restores') }}
                                        @endif
                                    </x-slot:content>
                                </x-popover>
                            @else
                                <x-button icon="o-trash" class="btn-ghost btn-xs text-error tooltip tooltip-left" wire:click="confirmDeleteSchedule('{{ $schedule->id }}')" :tooltip-left="__('Delete')" />
                            @endif
                        </div>
                    @endif
                @endscope
            </x-table>
        </x-card>

        <!-- Backup Configuration (editable) -->
        <x-card shadow class="min-w-0">
            <x-card-heading :title="__('Backup')" :subtitle="__('Backup and restore operation settings.')">
                <x-button
                    :label="__('Documentation')"
                    icon="o-book-open"
                    link="https://david-crty.github.io/databasement/self-hosting/configuration/backup"
                    external
                    class="btn-ghost btn-sm"
                />
            </x-card-heading>

            <form wire:submit="saveBackupConfig">
                <div class="divide-y divide-base-200/80">
                    <x-config-row :label="__('Working Directory')" :description="__('Temporary directory for backup and restore operations.')">
                        <x-input wire:model.blur="form.working_directory" :disabled="!$this->canManage" />
                    </x-config-row>

                    <x-config-row :label="__('Compression')">
                        <x-slot:description>
                            {{ __('Compression algorithm used for backup files.') }}
                            @if ($form->compression === 'encrypted')
                                {{ __('To customise the encryption key, check the') }}
                                <a href="https://david-crty.github.io/databasement/self-hosting/configuration/backup" target="_blank" class="link link-primary underline-offset-2">{{ __('documentation') }}</a>.
                            @endif
                        </x-slot:description>
                        <x-select wire:model.live="form.compression" :options="$compressionOptions" :disabled="!$this->canManage" />
                    </x-config-row>

                    @php
                        $compressionType = \App\Enums\CompressionType::tryFrom($form->compression);
                        $maxLevel = $compressionType?->maxLevel() ?? 19;
                    @endphp

                    <x-config-row :label="__('Compression Level')" :description="__('Higher levels compress smaller but run slower.')">
                        <div class="flex items-center gap-4">
                            <div class="flex-1">
                                <x-range
                                    wire:model.live.debounce.200ms="form.compression_level"
                                    :min="1"
                                    :max="$maxLevel"
                                    class="range-primary range-xs"
                                    :disabled="!$this->canManage"
                                />
                            </div>
                            <span class="badge badge-neutral shrink-0 font-mono whitespace-nowrap tabular-nums">{{ $form->compression_level }} / {{ $maxLevel }}</span>
                        </div>
                    </x-config-row>

                    @if ($compressionType?->supportsMultithreading())
                        <x-config-row :label="__('Multithreaded Compression')" :description="__('Use all available CPU cores to speed up compression.')">
                            <x-toggle wire:model.live="form.compression_multithread" :disabled="!$this->canManage" />
                        </x-config-row>
                    @endif

                    <x-config-row :label="__('Job Timeout')" :description="__('Maximum number of seconds a backup or restore job can run before timing out.')">
                        <x-input wire:model.blur="form.job_timeout" type="number" min="60" max="86400" :disabled="!$this->canManage" />
                    </x-config-row>

                    <x-config-row :label="__('Job Tries')" :description="__('Number of attempts before a job is marked as failed.')">
                        <x-input wire:model.blur="form.job_tries" type="number" min="1" max="10" :disabled="!$this->canManage" />
                    </x-config-row>

                    <x-config-row :label="__('Job Backoff')" :description="__('Number of seconds to wait before retrying a failed job.')">
                        <x-input wire:model.blur="form.job_backoff" type="number" min="0" max="3600" :disabled="!$this->canManage" />
                    </x-config-row>

                    <x-config-row :label="__('Cleanup Cron')" :description="__('Cron expression that controls when old snapshots are cleaned up.')">
                        <div class="flex items-start gap-2">
                            <div class="flex-1">
                                <x-input wire:model.blur="form.cleanup_cron" :disabled="!$this->canManage" />
                                <div class="fieldset-label mt-1 text-xs">{{ \App\Support\Formatters::cronTranslation($form->cleanup_cron) }}</div>
                            </div>
                            @if ($this->canManage)
                                <x-button icon="o-play" class="btn-ghost btn-sm mt-1" wire:click="runCleanup" spinner="runCleanup" :tooltip-left="__('Run now')" />
                            @endif
                        </div>
                    </x-config-row>

                    <x-config-row :label="__('Verify Snapshot Files')" :description="__('Periodically check that backup files still exist on their storage volumes.')">
                        <x-toggle wire:model.live="form.verify_files" :disabled="!$this->canManage" />
                    </x-config-row>

                    @if ($form->verify_files)
                        <x-config-row :label="__('Verify Files Cron')" :description="__('Cron expression that controls when snapshot file verification runs.')">
                            <div class="flex items-start gap-2">
                                <div class="flex-1">
                                    <x-input wire:model.blur="form.verify_files_cron" :disabled="!$this->canManage" />
                                    <div class="fieldset-label mt-1 text-xs">{{ \App\Support\Formatters::cronTranslation($form->verify_files_cron) }}</div>
                                </div>
                                @if ($this->canManage)
                                    <x-button icon="o-play" class="btn-ghost btn-sm mt-1" wire:click="runVerifyFiles" spinner="runVerifyFiles" :tooltip-left="__('Run now')" />
                                @endif
                            </div>
                        </x-config-row>
                    @endif
                </div>

                {{-- Hook scripts --}}
                @php
                    $backupPlaceholder = <<<'SH'
                    # Post-backup
                    curl -fsS -X POST https://example.com/hooks/backup \
                      -d "database=$BACKUP_DATABASE_NAME" \
                      -d "file=$BACKUP_FILENAME" \
                      -d "size=$BACKUP_FILE_SIZE"
                    SH;

                    $hookEditors = [
                        [
                            'field' => 'post_backup_script',
                            'title' => __('Post-backup Script'),
                            'icon' => 'o-arrow-down-tray',
                            'barClass' => 'bg-success/5',
                            'chipClass' => 'bg-success/10 text-success',
                            'placeholder' => $backupPlaceholder,
                            'vars' => [
                                'BACKUP_SERVER_ID' => __('Database server ID'),
                                'BACKUP_SERVER_NAME' => __('Database server name'),
                                'BACKUP_DATABASE_NAME' => __('Backed-up database name'),
                                'BACKUP_DATABASE_TYPE' => __('Database type (mysql, postgresql, …)'),
                                'BACKUP_FILENAME' => __('Path of the file written to the volume'),
                                'BACKUP_FILE_SIZE' => __('Backup file size in bytes'),
                                'BACKUP_CHECKSUM' => __('SHA-256 checksum of the backup'),
                                'BACKUP_VOLUME_NAME' => __('Destination volume name'),
                            ],
                        ],
                        [
                            'field' => 'post_restore_script',
                            'title' => __('Post-restore Script'),
                            'icon' => 'o-arrow-up-tray',
                            'barClass' => 'bg-info/5',
                            'chipClass' => 'bg-info/10 text-info',
                            'placeholder' => '',
                            'vars' => [
                                'RESTORE_SERVER_ID' => __('Target database server ID'),
                                'RESTORE_SERVER_NAME' => __('Target database server name'),
                                'RESTORE_DATABASE_NAME' => __('Target database name'),
                                'RESTORE_DATABASE_TYPE' => __('Database type (mysql, postgresql, …)'),
                                'RESTORE_SOURCE_DATABASE' => __('Original database name in the snapshot'),
                                'RESTORE_SNAPSHOT_FILENAME' => __('Snapshot file restored from the volume'),
                                'RESTORE_VOLUME_NAME' => __('Source volume name'),
                            ],
                        ],
                    ];
                @endphp

                <div class="border-t border-base-200/60 pt-6 mt-2 space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-base-200 text-base-content/70">
                            <x-icon name="o-command-line" class="w-4 h-4" />
                        </span>
                        <h3 class="text-sm font-semibold text-base-content">{{ __('Hook Scripts') }}</h3>
                    </div>

                    <x-alert icon="o-information-circle" class="alert-info">
                        {{ __('Run a shell script after every successful backup or restore. Scripts run with `:shebang` on the worker host, and their output appears in the job log. Find example in doc', ['shebang' => '#!/bin/sh']) }}
                        <x-slot:actions>
                            <x-button
                                :label="__('Learn more')"
                                link="https://david-crty.github.io/databasement/self-hosting/configuration/backup#hook-scripts"
                                external
                                icon="o-book-open"
                                class="btn-sm"
                            />
                        </x-slot:actions>
                    </x-alert>

                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        @foreach ($hookEditors as $editor)
                            <div wire:key="hook-{{ $editor['field'] }}" class="rounded-lg border border-base-300 bg-base-100 overflow-hidden">
                                {{-- Editor header --}}
                                <div class="flex items-center justify-between gap-2 px-3 py-2 border-b border-base-200 {{ $editor['barClass'] }}">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded {{ $editor['chipClass'] }}">
                                            <x-icon :name="$editor['icon']" class="w-3 h-3" />
                                        </span>
                                        <span class="text-sm font-medium text-base-content truncate">{{ $editor['title'] }}</span>
                                    </div>
                                    <span class="badge badge-ghost badge-sm font-mono text-[10px] shrink-0">#!/bin/sh</span>
                                </div>

                                {{-- Code editor --}}
                                <textarea
                                    wire:model.blur="form.{{ $editor['field'] }}"
                                    rows="6"
                                    spellcheck="false"
                                    @disabled(!$this->canManage)
                                    placeholder="{{ $editor['placeholder'] }}"
                                    class="w-full resize-y border-0 bg-base-200/30 p-3 font-mono text-xs leading-relaxed text-base-content focus:outline-none focus:ring-0 disabled:opacity-60 disabled:cursor-not-allowed"
                                ></textarea>

                                {{-- Footer: variable reference --}}
                                <div class="border-t border-base-200 px-3 py-2" x-data="{ open: false }">
                                    <button
                                        type="button"
                                        x-on:click="open = !open"
                                        class="flex items-center gap-1 text-xs font-medium text-base-content/70 hover:text-base-content"
                                    >
                                        <span class="transition-transform" x-bind:class="{ 'rotate-90': open }">
                                            <x-icon name="o-chevron-right" class="w-3 h-3" />
                                        </span>
                                        {{ __('Available variables') }}
                                    </button>

                                    <div x-show="open" x-collapse class="mt-2 flex flex-wrap gap-1.5">
                                        @foreach ($editor['vars'] as $var => $desc)
                                            <code
                                                title="{{ $desc }}"
                                                class="cursor-help rounded bg-base-200 px-1.5 py-0.5 font-mono text-[11px] text-base-content/70"
                                            >${{ $var }}</code>
                                        @endforeach
                                    </div>
                                </div>

                                @error('form.'.$editor['field'])
                                    <p class="px-3 pb-2 text-xs text-error">{{ $message }}</p>
                                @enderror
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($this->canManage)
                <div class="flex items-center justify-end border-t border-base-200/60 pt-6">
                    <x-button
                        type="submit"
                        class="btn-primary"
                        :label="__('Save Backup Settings')"
                        spinner="saveBackupConfig"
                    />
                </div>
                @endif
            </form>
        </x-card>
    </div>

    <!-- Add/Edit Schedule Modal -->
    <x-modal wire:model="showScheduleModal" :title="$editingScheduleId ? __('Edit Schedule') : __('Add Schedule')">
        <div class="space-y-4">
            <x-input
                wire:model="form.schedule_name"
                :label="__('Name')"
                :placeholder="__('e.g., Every 3 Hours')"
                required
            />

            <div>
                <x-input
                    wire:model.live="form.schedule_expression"
                    :label="__('Cron Expression')"
                    :placeholder="__('e.g., 0 */3 * * *')"
                    required
                />
                @if ($form->schedule_expression)
                    <div class="fieldset-label mt-1 text-xs">{{ \App\Support\Formatters::cronTranslation($form->schedule_expression, 'Invalid cron expression') }}</div>
                @endif
            </div>
        </div>

        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showScheduleModal = false" />
            <x-button
                class="btn-primary"
                :label="__('Save')"
                wire:click="saveSchedule"
                spinner="saveSchedule"
            />
        </x-slot:actions>
    </x-modal>

    <!-- Delete Schedule Confirmation Modal -->
    <x-modal wire:model="showDeleteScheduleModal" :title="__('Delete Schedule')">
        <p>{{ __('Are you sure you want to delete this backup schedule? This action cannot be undone.') }}</p>

        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showDeleteScheduleModal = false" />
            <x-button
                class="btn-error"
                :label="__('Delete')"
                wire:click="deleteSchedule"
                spinner="deleteSchedule"
            />
        </x-slot:actions>
    </x-modal>
</div>
