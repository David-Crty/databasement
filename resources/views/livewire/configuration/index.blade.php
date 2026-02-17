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
                                    @if ($schedule->backups_count > 0)
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

        <!-- Notification Configuration (editable) -->
        <x-card title="{{ __('Notifications') }}" subtitle="{{ __('Failure notification settings for backup and restore jobs.') }}" shadow class="min-w-0">
            <x-slot:menu>
                <x-button
                    label="{{ __('Documentation') }}"
                    icon="o-book-open"
                    link="https://david-crty.github.io/databasement/self-hosting/configuration/notification"
                    external
                    class="btn-ghost btn-sm"
                />
            </x-slot:menu>

            <form wire:submit="saveNotificationConfig" x-data="{ dirty: false }" x-init="$watch('$wire.form.channels', () => dirty = true)" @input="dirty = true" @change="dirty = true" @notification-saved.window="dirty = false">
                <x-config-row label="{{ __('Enable Notifications') }}" description="{{ __('Send notifications when backup or restore jobs fail.') }}">
                    <x-toggle wire:model="form.notifications_enabled" :disabled="!$this->isAdmin" />
                </x-config-row>

                <x-hr class="my-2" />

                <x-config-row label="{{ __('Channels') }}" description="{{ __('Select which channels receive failure notifications.') }}">
                    <x-choices-offline wire:model.live="form.channels" :options="$channelOptions" :disabled="!$this->isAdmin" />
                </x-config-row>

                @if (in_array('email', $form->channels))
                    <x-hr class="my-2" />

                    <x-config-row label="{{ __('Mail Recipient') }}">
                        <x-slot:description>
                            {{ __('Email address that receives failure notifications.') }}
                            {{ __('Ensure your MAIL_ environment variables are') }}
                            <a href="https://david-crty.github.io/databasement/self-hosting/configuration/notification#email" target="_blank" class="link link-primary underline-offset-2">{{ __('configured') }}</a>.
                        </x-slot:description>
                        <x-input wire:model.blur="form.mail_to" type="email" :disabled="!$this->isAdmin" />
                    </x-config-row>
                @endif

                @if (in_array('slack', $form->channels))
                    <x-hr class="my-2" />

                    <x-config-row label="{{ __('Slack Webhook URL') }}">
                        <x-slot:description>
                            {{ __('Incoming webhook URL for Slack notifications.') }}
                            @if ($form->has_slack_webhook_url)
                                {{ __('Leave blank to keep the current value.') }}
                            @endif
                        </x-slot:description>
                        <x-input wire:model.blur="form.slack_webhook_url" type="password" placeholder="{{ $form->has_slack_webhook_url ? '********' : '' }}" :disabled="!$this->isAdmin" />
                    </x-config-row>
                @endif

                @if (in_array('discord', $form->channels))
                    <x-hr class="my-2" />

                    <x-config-row label="{{ __('Discord Bot Token') }}">
                        <x-slot:description>
                            {{ __('Bot token for Discord notifications.') }}
                            @if ($form->has_discord_token)
                                {{ __('Leave blank to keep the current value.') }}
                            @endif
                        </x-slot:description>
                        <x-input wire:model.blur="form.discord_token" type="password" placeholder="{{ $form->has_discord_token ? '********' : '' }}" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    <x-config-row label="{{ __('Discord Channel ID') }}" description="{{ __('The Discord channel where failure notifications are sent.') }}">
                        <x-input wire:model.blur="form.discord_channel_id" :disabled="!$this->isAdmin" />
                    </x-config-row>
                @endif

                @if (in_array('telegram', $form->channels))
                    <x-hr class="my-2" />

                    <x-config-row label="{{ __('Telegram Bot Token') }}">
                        <x-slot:description>
                            {{ __('Bot token from @BotFather for Telegram notifications.') }}
                            @if ($form->has_telegram_bot_token)
                                {{ __('Leave blank to keep the current value.') }}
                            @endif
                        </x-slot:description>
                        <x-input wire:model.blur="form.telegram_bot_token" type="password" placeholder="{{ $form->has_telegram_bot_token ? '********' : '' }}" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    <x-config-row label="{{ __('Telegram Chat ID') }}" description="{{ __('The chat or group ID where failure notifications are sent.') }}">
                        <x-input wire:model.blur="form.telegram_chat_id" :disabled="!$this->isAdmin" />
                    </x-config-row>
                @endif

                @if (in_array('pushover', $form->channels))
                    <x-hr class="my-2" />

                    <x-config-row label="{{ __('Pushover App Token') }}">
                        <x-slot:description>
                            {{ __('Application API token from Pushover.') }}
                            @if ($form->has_pushover_token)
                                {{ __('Leave blank to keep the current value.') }}
                            @endif
                        </x-slot:description>
                        <x-input wire:model.blur="form.pushover_token" type="password" placeholder="{{ $form->has_pushover_token ? '********' : '' }}" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    <x-config-row label="{{ __('Pushover User Key') }}">
                        <x-slot:description>
                            {{ __('Your Pushover user or group key.') }}
                            @if ($form->has_pushover_user_key)
                                {{ __('Leave blank to keep the current value.') }}
                            @endif
                        </x-slot:description>
                        <x-input wire:model.blur="form.pushover_user_key" type="password" placeholder="{{ $form->has_pushover_user_key ? '********' : '' }}" :disabled="!$this->isAdmin" />
                    </x-config-row>
                @endif

                @if (in_array('gotify', $form->channels))
                    <x-hr class="my-2" />

                    <x-config-row label="{{ __('Gotify Server URL') }}" description="{{ __('Base URL of your Gotify server (e.g. https://gotify.example.com).') }}">
                        <x-input wire:model.blur="form.gotify_url" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    <x-config-row label="{{ __('Gotify App Token') }}">
                        <x-slot:description>
                            {{ __('Application token from your Gotify server.') }}
                            @if ($form->has_gotify_token)
                                {{ __('Leave blank to keep the current value.') }}
                            @endif
                        </x-slot:description>
                        <x-input wire:model.blur="form.gotify_token" type="password" placeholder="{{ $form->has_gotify_token ? '********' : '' }}" :disabled="!$this->isAdmin" />
                    </x-config-row>
                @endif

                @if (in_array('webhook', $form->channels))
                    <x-hr class="my-2" />

                    <x-config-row label="{{ __('Webhook URL') }}" description="{{ __('URL that receives a JSON POST with notification details.') }}">
                        <x-input wire:model.blur="form.webhook_url" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    <x-config-row label="{{ __('Webhook Secret') }}">
                        <x-slot:description>
                            {{ __('Optional secret. Sent as X-Webhook-Token header.') }}
                            @if ($form->has_webhook_secret)
                                {{ __('Leave blank to keep the current value.') }}
                            @endif
                        </x-slot:description>
                        <x-input wire:model.blur="form.webhook_secret" type="password" placeholder="{{ $form->has_webhook_secret ? '********' : '' }}" :disabled="!$this->isAdmin" />
                    </x-config-row>
                @endif

                @if ($this->isAdmin)
                <div class="flex items-center justify-end gap-2 border-t border-base-200/60 pt-6">
                    {{-- Send Test: disabled with popover when form is dirty or notifications are off --}}
                    <div x-show="dirty || !$wire.form.notifications_enabled" x-cloak>
                        <x-popover>
                            <x-slot:trigger>
                                <x-button
                                    label="{{ __('Send Test') }}"
                                    icon="o-paper-airplane"
                                    class="btn-outline"
                                    disabled
                                />
                            </x-slot:trigger>
                            <x-slot:content>
                                <span x-show="dirty">{{ __('Save before testing.') }}</span>
                                <span x-show="!dirty">{{ __('Enable notifications to send a test.') }}</span>
                            </x-slot:content>
                        </x-popover>
                    </div>

                    {{-- Send Test: enabled when form is clean and notifications are on --}}
                    <div x-show="!dirty && $wire.form.notifications_enabled" x-cloak>
                        <x-button
                            label="{{ __('Send Test') }}"
                            icon="o-paper-airplane"
                            wire:click="sendTestNotification"
                            wire:loading.attr="disabled"
                            spinner="sendTestNotification"
                            class="btn-outline"
                        />
                    </div>

                    <x-button
                        type="submit"
                        class="btn-primary"
                        label="{{ __('Save Notification Settings') }}"
                        spinner="saveNotificationConfig"
                    />
                </div>
                @endif
            </form>
        </x-card>

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
    <x-modal wire:model="showDeleteScheduleModal" title="{{ __('Delete Schedule') }}">
        <p>{{ __('Are you sure you want to delete this backup schedule? This action cannot be undone.') }}</p>

        <x-slot:actions>
            <x-button label="{{ __('Cancel') }}" @click="$wire.showDeleteScheduleModal = false" />
            <x-button
                class="btn-error"
                label="{{ __('Delete') }}"
                wire:click="deleteSchedule"
                spinner="deleteSchedule"
            />
        </x-slot:actions>
    </x-modal>
</div>
