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

    <div class="grid gap-6">
        <x-alert class="alert-info" icon="o-information-circle">
            {{ __('View the full list of environment variables') }}
            <x-slot:actions>
                <x-button
                    label="{{ __('Documentation') }}"
                    icon="o-book-open"
                    link="https://david-crty.github.io/databasement/self-hosting/configuration"
                    external
                    class="btn-ghost"
                />
            </x-slot:actions>
        </x-alert>

        <!-- Application Configuration (read-only) -->
        <x-card title="{{ __('Application') }}" subtitle="{{ __('General application settings (read-only).') }}" shadow>
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

        <!-- Backup Configuration (editable) -->
        <x-card title="{{ __('Backup') }}" subtitle="{{ __('Backup and restore operation settings.') }}" shadow>
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

                    <x-config-row label="{{ __('Daily Backup Cron') }}" description="{{ __('Cron expression that controls when daily backups run.') }}">
                        <div>
                            <x-input wire:model.blur="form.daily_cron" :disabled="!$this->isAdmin" />
                            <div class="fieldset-label mt-1 text-xs">{{ $this->translateCron($form->daily_cron) }}</div>
                        </div>
                    </x-config-row>

                    <x-config-row label="{{ __('Weekly Backup Cron') }}" description="{{ __('Cron expression that controls when weekly backups run.') }}">
                        <div>
                            <x-input wire:model.blur="form.weekly_cron" :disabled="!$this->isAdmin" />
                            <div class="fieldset-label mt-1 text-xs">{{ $this->translateCron($form->weekly_cron) }}</div>
                        </div>
                    </x-config-row>

                    <x-config-row label="{{ __('Cleanup Cron') }}" description="{{ __('Cron expression that controls when old snapshots are cleaned up.') }}">
                        <div>
                            <x-input wire:model.blur="form.cleanup_cron" :disabled="!$this->isAdmin" />
                            <div class="fieldset-label mt-1 text-xs">{{ $this->translateCron($form->cleanup_cron) }}</div>
                        </div>
                    </x-config-row>

                    <x-config-row label="{{ __('Verify Snapshot Files') }}" description="{{ __('Periodically check that backup files still exist on their storage volumes.') }}">
                        <x-toggle wire:model.live="form.verify_files" :disabled="!$this->isAdmin" />
                    </x-config-row>

                    @if ($form->verify_files)
                        <x-config-row label="{{ __('Verify Files Cron') }}" description="{{ __('Cron expression that controls when snapshot file verification runs.') }}">
                            <div>
                                <x-input wire:model.blur="form.verify_files_cron" :disabled="!$this->isAdmin" />
                                <div class="fieldset-label mt-1 text-xs">{{ $this->translateCron($form->verify_files_cron) }}</div>
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
        <x-card title="{{ __('Notifications') }}" subtitle="{{ __('Failure notification settings for backup and restore jobs.') }}" shadow>
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
        <x-card title="{{ __('SSO') }}" subtitle="{{ __('OAuth and Single Sign-On authentication settings (read-only).') }}" shadow>
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
</div>
