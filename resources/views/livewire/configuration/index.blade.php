<div>
    <x-header title="{{ __('Configuration') }}" separator>
        <x-slot:subtitle>
            @if ($this->isAdmin)
                {{ __('Manage application settings. Backup and notification settings are editable.') }}
            @else
                {{ __('View application settings. Only administrators can modify these settings.') }}
            @endif
        </x-slot:subtitle>
    </x-header>
    <div class="alert alert-info alert-vertical sm:alert-horizontal rounded-md mb-2">
        <x-icon name="o-information-circle" class="shrink-0" />
        <span>{{ __('View the full list of environment variables') }}</span>
        <x-button
            label="{{ __('Documentation') }}"
            icon="o-book-open"
            link="https://david-crty.github.io/databasement/self-hosting/configuration"
            external
            class="btn-ghost btn-sm"
        />
    </div>

    @if($showDeprecatedBackupEnv)
        <x-alert class="alert-warning mb-2" icon="o-exclamation-triangle" dismissible>
            {{ __('Deprecated BACKUP_* environment variables detected. Backup settings are now configured in the UI. You can safely remove BACKUP_* variables from your environment.') }}
        </x-alert>
    @endif

    <div class="grid gap-6">
        <!-- Application Configuration (read-only) -->
        <x-card title="{{ __('Application') }}" subtitle="{{ __('General application settings (read-only).') }}" shadow class="min-w-0">
            <x-slot:menu>
                <x-button
                    label="{{ __('Documentation') }}"
                    icon="o-book-open"
                    link="https://david-crty.github.io/databasement/self-hosting/configuration/application"
                    external
                    class="btn-ghost btn-sm"
                />
            </x-slot:menu>
            @include('livewire.configuration._config-table', ['rows' => $appConfig])
        </x-card>

        <!-- Backup Schedules -->
        <x-card title="{{ __('Backup Schedules') }}" subtitle="{{ __('Define cron schedules that database servers can use for automated backups.') }}" shadow class="min-w-0">
            <div class="divide-y divide-base-200/80">
                @forelse ($backupSchedules as $schedule)
                    <x-config-row wire:key="schedule-{{ $schedule->id }}">
                        <x-slot:label>
                            <span class="inline-flex flex-wrap items-center gap-2">
                                {{ $schedule->name }}
                                @if ($schedule->backups_count > 0)
                                    <x-popover>
                                        <x-slot:trigger>
                                            <span class="badge badge-outline badge-info cursor-default">
                                                <x-icon name="o-server-stack" class="w-3 h-3" />
                                                {{ trans_choice(':count server|:count servers', $schedule->backups_count) }}
                                            </span>
                                        </x-slot:trigger>
                                        <x-slot:content>
                                            {{ $schedule->backups->pluck('databaseServer.name')->join(', ') }}
                                        </x-slot:content>
                                    </x-popover>
                                @endif
                            </span>
                        </x-slot:label>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="badge badge-neutral shrink-0">
                                <x-icon name="o-calendar-days" class="w-3 h-3" />
                                {{ $schedule->expression }}
                            </span>
                            <span class="text-sm text-base-content/60 min-w-0">{{ \App\Support\Formatters::cronTranslation($schedule->expression) }}</span>
                            @if ($this->isAdmin)
                                <div class="flex items-center gap-0.5 shrink-0 ml-auto">
                                    @if ($schedule->backups_count > 0)
                                        <x-button icon="o-play" class="btn-ghost btn-sm" wire:click="runSchedule('{{ $schedule->id }}')" spinner="runSchedule('{{ $schedule->id }}')" tooltip-left="{{ __('Run now') }}" />
                                    @endif
                                    <x-button icon="o-pencil-square" class="btn-ghost btn-sm" wire:click="openScheduleModal('{{ $schedule->id }}')" tooltip-left="{{ __('Edit') }}" />
                                    @if ($schedule->total_backups_count > 0)
                                        <x-popover>
                                            <x-slot:trigger>
                                                <x-button icon="o-trash" class="btn-ghost btn-sm opacity-40" disabled />
                                            </x-slot:trigger>
                                            <x-slot:content>{{ __('In use by servers') }}</x-slot:content>
                                        </x-popover>
                                    @else
                                        <x-button icon="o-trash" class="btn-ghost btn-sm text-error hover:bg-error/10" wire:click="confirmDeleteSchedule('{{ $schedule->id }}')" tooltip-left="{{ __('Delete') }}" />
                                    @endif
                                </div>
                            @endif
                        </div>
                    </x-config-row>
                @empty
                    <p class="text-sm text-base-content/50 py-4 text-center">{{ __('No backup schedules defined.') }}</p>
                @endforelse
            </div>

            @if ($this->isAdmin)
                <div class="flex items-center justify-end border-t border-base-200/60 pt-4 mt-4">
                    <x-button
                        label="{{ __('Add Schedule') }}"
                        icon="o-plus"
                        class="btn-primary btn-sm"
                        wire:click="openScheduleModal"
                    />
                </div>
            @endif
        </x-card>

        <!-- Backup Configuration (editable) -->
        <x-card title="{{ __('Backup') }}" subtitle="{{ __('Backup and restore operation settings.') }}" shadow class="min-w-0">
            <x-slot:menu>
                <x-button
                    label="{{ __('Documentation') }}"
                    icon="o-book-open"
                    link="https://david-crty.github.io/databasement/self-hosting/configuration/backup"
                    external
                    class="btn-ghost btn-sm"
                />
            </x-slot:menu>

            <form wire:submit="saveBackupConfig">
                <div class="divide-y divide-base-200/80">
                    <x-config-row label="{{ __('Working Directory') }}" description="{{ __('Temporary directory for backup and restore operations.') }}">
                        <x-input wire:model.blur="form.working_directory" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    <x-config-row label="{{ __('Compression') }}">
                        <x-slot:description>
                            {{ __('Compression algorithm used for backup files.') }}
                            @if ($form->compression === 'encrypted')
                                {{ __('To customise the encryption key, check the') }}
                                <a href="https://david-crty.github.io/databasement/self-hosting/configuration/backup" target="_blank" class="link link-primary underline-offset-2">{{ __('documentation') }}</a>.
                            @endif
                        </x-slot:description>
                        <x-select wire:model.live="form.compression" :options="$compressionOptions" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    <x-config-row label="{{ __('Compression Level') }}" description="{{ __('1-9 for gzip/encrypted, 1-19 for zstd.') }}">
                        <x-input wire:model.blur="form.compression_level" type="number" min="1" max="19" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    <x-config-row label="{{ __('Job Timeout') }}" description="{{ __('Maximum number of seconds a backup or restore job can run before timing out.') }}">
                        <x-input wire:model.blur="form.job_timeout" type="number" min="60" max="86400" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    <x-config-row label="{{ __('Job Tries') }}" description="{{ __('Number of attempts before a job is marked as failed.') }}">
                        <x-input wire:model.blur="form.job_tries" type="number" min="1" max="10" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    <x-config-row label="{{ __('Job Backoff') }}" description="{{ __('Number of seconds to wait before retrying a failed job.') }}">
                        <x-input wire:model.blur="form.job_backoff" type="number" min="0" max="3600" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    <x-config-row label="{{ __('Cleanup Cron') }}" description="{{ __('Cron expression that controls when old snapshots are cleaned up.') }}">
                        <div class="flex items-start gap-2">
                            <div class="flex-1">
                                <x-input wire:model.blur="form.cleanup_cron" :disabled="!$this->isAdmin" />
                                <div class="fieldset-label mt-1 text-xs">{{ \App\Support\Formatters::cronTranslation($form->cleanup_cron) }}</div>
                            </div>
                            @if ($this->isAdmin)
                                <x-button icon="o-play" class="btn-ghost btn-sm mt-1" wire:click="runCleanup" spinner="runCleanup" tooltip-left="{{ __('Run now') }}" />
                            @endif
                        </div>
                    </x-config-row>

                    <x-config-row label="{{ __('Verify Snapshot Files') }}" description="{{ __('Periodically check that backup files still exist on their storage volumes.') }}">
                        <x-toggle wire:model.live="form.verify_files" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    @if ($form->verify_files)
                        <x-config-row label="{{ __('Verify Files Cron') }}" description="{{ __('Cron expression that controls when snapshot file verification runs.') }}">
                            <div class="flex items-start gap-2">
                                <div class="flex-1">
                                    <x-input wire:model.blur="form.verify_files_cron" :disabled="!$this->isAdmin" />
                                    <div class="fieldset-label mt-1 text-xs">{{ \App\Support\Formatters::cronTranslation($form->verify_files_cron) }}</div>
                                </div>
                                @if ($this->isAdmin)
                                    <x-button icon="o-play" class="btn-ghost btn-sm mt-1" wire:click="runVerifyFiles" spinner="runVerifyFiles" tooltip-left="{{ __('Run now') }}" />
                                @endif
                            </div>
                        </x-config-row>
                    @endif
                </div>

                @if ($this->isAdmin)
                <div class="flex items-center justify-end border-t border-base-200/60 pt-6">
                    <x-button
                        type="submit"
                        class="btn-primary"
                        label="{{ __('Save Backup Settings') }}"
                        spinner="saveBackupConfig"
                    />
                </div>
                @endif
            </form>
        </x-card>

        <!-- Notification Channels -->
        <div id="notification-channels" class="scroll-mt-20">
        <x-card :title="__('Notification Channels')" :subtitle="__('Manage notification channels for backup and restore events. Assign channels per database server.')" shadow class="min-w-0">
            <x-slot:menu>
                <x-button
                    :label="__('Documentation')"
                    icon="o-book-open"
                    link="https://david-crty.github.io/databasement/self-hosting/configuration/notification"
                    external
                    class="btn-ghost btn-sm"
                />
            </x-slot:menu>

            <div class="divide-y divide-base-200/80">
                @forelse ($notificationChannels as $channel)
                    <x-config-row wire:key="channel-{{ $channel->id }}">
                        <x-slot:label>
                            <span class="inline-flex flex-wrap items-center gap-2">
                                <x-icon :name="$channel->type->icon()" class="w-4 h-4 text-base-content/60" />
                                {{ $channel->name }}
                                <span class="badge badge-outline badge-sm">{{ $channel->type->label() }}</span>
                            </span>
                        </x-slot:label>
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-sm text-base-content/60 min-w-0">
                                @foreach($channel->getConfigSummary() as $label => $value)
                                    {{ $label }}: {{ $value }}{{ !$loop->last ? ' · ' : '' }}
                                @endforeach
                            </span>
                            @if ($this->isAdmin)
                                <div class="flex items-center gap-0.5 shrink-0 ml-auto">
                                    <x-button icon="o-paper-airplane" class="btn-ghost btn-sm" wire:click="sendTestNotification('{{ $channel->id }}')" spinner="sendTestNotification('{{ $channel->id }}')" tooltip-left="{{ __('Test') }}" />
                                    <x-button icon="o-pencil-square" class="btn-ghost btn-sm" wire:click="openChannelModal('{{ $channel->id }}')" tooltip-left="{{ __('Edit') }}" />
                                    <x-button icon="o-trash" class="btn-ghost btn-sm text-error hover:bg-error/10" wire:click="confirmDeleteChannel('{{ $channel->id }}')" tooltip-left="{{ __('Delete') }}" />
                                </div>
                            @endif
                        </div>
                    </x-config-row>
                @empty
                    <p class="text-sm text-base-content/50 py-4 text-center">{{ __('No notification channels configured.') }}</p>
                @endforelse
            </div>

            @if ($this->isAdmin)
                <div class="flex items-center justify-end border-t border-base-200/60 pt-4 mt-4">
                    <x-button
                        :label="__('Add Channel')"
                        icon="o-plus"
                        class="btn-primary btn-sm"
                        wire:click="openChannelModal"
                    />
                </div>
            @endif
        </x-card>
        </div>

        <!-- SSO Configuration (read-only) -->
        <x-card title="{{ __('SSO') }}" subtitle="{{ __('OAuth and Single Sign-On authentication settings (read-only).') }}" shadow class="min-w-0">
            <x-slot:menu>
                <x-button
                    label="{{ __('Documentation') }}"
                    icon="o-book-open"
                    link="https://david-crty.github.io/databasement/self-hosting/configuration/sso"
                    external
                    class="btn-ghost btn-sm"
                />
            </x-slot:menu>
            @include('livewire.configuration._config-table', ['rows' => $ssoConfig])
        </x-card>
    </div>

    <!-- Add/Edit Schedule Modal -->
    <x-modal wire:model="showScheduleModal" title="{{ $editingScheduleId ? __('Edit Schedule') : __('Add Schedule') }}">
        <div class="space-y-4">
            <x-input
                wire:model="form.schedule_name"
                label="{{ __('Name') }}"
                placeholder="{{ __('e.g., Every 3 Hours') }}"
                required
            />

            <div>
                <x-input
                    wire:model.live="form.schedule_expression"
                    label="{{ __('Cron Expression') }}"
                    placeholder="{{ __('e.g., 0 */3 * * *') }}"
                    required
                />
                @if ($form->schedule_expression)
                    <div class="fieldset-label mt-1 text-xs">{{ \App\Support\Formatters::cronTranslation($form->schedule_expression, 'Invalid cron expression') }}</div>
                @endif
            </div>
        </div>

        <x-slot:actions>
            <x-button label="{{ __('Cancel') }}" @click="$wire.showScheduleModal = false" />
            <x-button
                class="btn-primary"
                label="{{ __('Save') }}"
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

    <!-- Add/Edit Notification Channel Modal -->
    <x-modal wire:model="showChannelModal" :title="$editingChannelId ? __('Edit Channel') : __('Add Channel')">
        <div class="space-y-4">
            <x-input
                wire:model="channelForm.name"
                :label="__('Name')"
                :placeholder="__('e.g., DBA Team Alerts')"
                required
            />

            @if (!$editingChannelId)
                <x-select
                    wire:model.live="channelForm.type"
                    :label="__('Type')"
                    :options="$channelTypeOptions"
                />
            @else
                <x-input
                    :value="$channelForm->channel?->type->label()"
                    :label="__('Type')"
                    disabled
                />
            @endif

            {{-- Type-specific config fields --}}
            @if ($channelForm->type === 'email')
                <x-input wire:model="channelForm.config_to" :label="__('Recipient Email')" type="email" required />
            @elseif ($channelForm->type === 'slack')
                <x-password wire:model="channelForm.config_webhook_url" :label="__('Webhook URL')" :placeholder="$channelForm->has_config_webhook_url ? '********' : ''" />
            @elseif ($channelForm->type === 'discord')
                <x-password wire:model="channelForm.config_token" :label="__('Bot Token')" :placeholder="$channelForm->has_config_token ? '********' : ''" />
                <x-input wire:model="channelForm.config_channel_id" :label="__('Channel ID')" required />
            @elseif ($channelForm->type === 'discord_webhook')
                <x-password wire:model="channelForm.config_url" :label="__('Webhook URL')" :placeholder="$channelForm->has_config_url ? '********' : ''" />
            @elseif ($channelForm->type === 'telegram')
                <x-password wire:model="channelForm.config_bot_token" :label="__('Bot Token')" :placeholder="$channelForm->has_config_bot_token ? '********' : ''" />
                <x-input wire:model="channelForm.config_chat_id" :label="__('Chat ID')" required />
            @elseif ($channelForm->type === 'pushover')
                <x-password wire:model="channelForm.config_token" :label="__('App Token')" :placeholder="$channelForm->has_config_token ? '********' : ''" />
                <x-password wire:model="channelForm.config_user_key" :label="__('User Key')" :placeholder="$channelForm->has_config_user_key ? '********' : ''" />
            @elseif ($channelForm->type === 'gotify')
                <x-input wire:model="channelForm.config_url" :label="__('Server URL')" :placeholder="__('https://gotify.example.com')" required />
                <x-password wire:model="channelForm.config_token" :label="__('App Token')" :placeholder="$channelForm->has_config_token ? '********' : ''" />
            @elseif ($channelForm->type === 'webhook')
                <x-input wire:model="channelForm.config_url" :label="__('Webhook URL')" required />
                <x-password wire:model="channelForm.config_secret" :label="__('Secret (optional)')" :placeholder="$channelForm->has_config_secret ? '********' : ''" />
            @endif

            @if ($editingChannelId)
                <p class="text-xs text-base-content/50">{{ __('Leave password fields blank to keep existing values.') }}</p>
            @endif
        </div>

        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showChannelModal = false" />
            <x-button
                class="btn-primary"
                :label="__('Save')"
                wire:click="saveChannel"
                spinner="saveChannel"
            />
        </x-slot:actions>
    </x-modal>

    <!-- Delete Channel Confirmation Modal -->
    <x-modal wire:model="showDeleteChannelModal" :title="__('Delete Channel')">
        <p>{{ __('Are you sure you want to delete this notification channel? This action cannot be undone.') }}</p>

        <x-slot:actions>
            <x-button :label="__('Cancel')" @click="$wire.showDeleteChannelModal = false" />
            <x-button
                class="btn-error"
                :label="__('Delete')"
                wire:click="deleteChannel"
                spinner="deleteChannel"
            />
        </x-slot:actions>
    </x-modal>
</div>
