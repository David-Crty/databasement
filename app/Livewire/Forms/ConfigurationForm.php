<?php

namespace App\Livewire\Forms;

use App\Facades\AppConfig;
use Cron\CronExpression;
use Livewire\Form;

class ConfigurationForm extends Form
{
    // Backup settings
    public string $working_directory = '';

    public string $compression = '';

    public int $compression_level = 6;

    public int $job_timeout = 7200;

    public int $job_tries = 3;

    public int $job_backoff = 60;

    public string $cleanup_cron = '';

    public bool $verify_files = true;

    public string $verify_files_cron = '';

    // Notification settings
    public bool $notifications_enabled = false;

    /** @var array<int, string> */
    public array $channels = [];

    public string $mail_to = '';

    public string $slack_webhook_url = '';

    public bool $has_slack_webhook_url = false;

    public string $discord_token = '';

    public bool $has_discord_token = false;

    public string $discord_channel_id = '';

    public string $telegram_bot_token = '';

    public bool $has_telegram_bot_token = false;

    public string $telegram_chat_id = '';

    public string $pushover_token = '';

    public bool $has_pushover_token = false;

    public string $pushover_user_key = '';

    public bool $has_pushover_user_key = false;

    public string $gotify_url = '';

    public string $gotify_token = '';

    public bool $has_gotify_token = false;

    public string $webhook_url = '';

    public string $webhook_secret = '';

    public bool $has_webhook_secret = false;

    // Backup Schedule modal fields
    public string $schedule_name = '';

    public string $schedule_expression = '';

    public function loadFromConfig(): void
    {
        $this->working_directory = (string) AppConfig::get('backup.working_directory');
        $this->compression = (string) AppConfig::get('backup.compression');
        $this->compression_level = (int) AppConfig::get('backup.compression_level');
        $this->job_timeout = (int) AppConfig::get('backup.job_timeout');
        $this->job_tries = (int) AppConfig::get('backup.job_tries');
        $this->job_backoff = (int) AppConfig::get('backup.job_backoff');
        $this->cleanup_cron = (string) AppConfig::get('backup.cleanup_cron');
        $this->verify_files = (bool) AppConfig::get('backup.verify_files');
        $this->verify_files_cron = (string) AppConfig::get('backup.verify_files_cron');
        $this->notifications_enabled = (bool) AppConfig::get('notifications.enabled');
        $this->mail_to = (string) AppConfig::get('notifications.mail.to');
        $this->has_slack_webhook_url = (bool) AppConfig::get('notifications.slack.webhook_url');
        $this->has_discord_token = (bool) AppConfig::get('notifications.discord.token');
        $this->discord_channel_id = (string) AppConfig::get('notifications.discord.channel_id');
        $this->has_telegram_bot_token = (bool) AppConfig::get('notifications.telegram.bot_token');
        $this->telegram_chat_id = (string) AppConfig::get('notifications.telegram.chat_id');
        $this->has_pushover_token = (bool) AppConfig::get('notifications.pushover.token');
        $this->has_pushover_user_key = (bool) AppConfig::get('notifications.pushover.user_key');
        $this->gotify_url = (string) AppConfig::get('notifications.gotify.url');
        $this->has_gotify_token = (bool) AppConfig::get('notifications.gotify.token');
        $this->webhook_url = (string) AppConfig::get('notifications.webhook.url');
        $this->has_webhook_secret = (bool) AppConfig::get('notifications.webhook.secret');

        // Pre-select channels that already have values configured
        $this->channels = [];
        if ($this->mail_to !== '') {
            $this->channels[] = 'email';
        }
        if ($this->has_slack_webhook_url) {
            $this->channels[] = 'slack';
        }
        if ($this->has_discord_token || $this->discord_channel_id !== '') {
            $this->channels[] = 'discord';
        }
        if ($this->has_telegram_bot_token || $this->telegram_chat_id !== '') {
            $this->channels[] = 'telegram';
        }
        if ($this->has_pushover_token || $this->has_pushover_user_key) {
            $this->channels[] = 'pushover';
        }
        if ($this->gotify_url !== '' || $this->has_gotify_token) {
            $this->channels[] = 'gotify';
        }
        if ($this->webhook_url !== '') {
            $this->channels[] = 'webhook';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function backupRules(): array
    {
        return [
            'working_directory' => ['required', 'string', 'max:500', new \App\Rules\SafePath(allowAbsolute: true)],
            'compression' => ['required', 'string', 'in:gzip,zstd,encrypted'],
            'compression_level' => ['required', 'integer', 'min:1', 'max:'.($this->compression === 'gzip' ? 9 : 19)],
            'job_timeout' => ['required', 'integer', 'min:60', 'max:86400'],
            'job_tries' => ['required', 'integer', 'min:1', 'max:10'],
            'job_backoff' => ['required', 'integer', 'min:0', 'max:3600'],
            'cleanup_cron' => ['required', 'string', 'max:100', $this->cronRule()],
            'verify_files' => ['boolean'],
            'verify_files_cron' => ['required', 'string', 'max:100', $this->cronRule()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationRules(): array
    {
        return [
            'notifications_enabled' => ['boolean'],
            'channels' => [function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->notifications_enabled && empty($value)) {
                    $fail(__('Select at least one channel when notifications are enabled.'));
                }
            }],
            'mail_to' => [$this->channelSelected('email') ? 'required' : 'nullable', 'email', 'max:255'],
            'slack_webhook_url' => [$this->channelSelected('slack') && ! $this->has_slack_webhook_url ? 'required' : 'nullable', 'string', 'url', 'max:500'],
            'discord_token' => [$this->channelSelected('discord') && ! $this->has_discord_token ? 'required' : 'nullable', 'string', 'max:500'],
            'discord_channel_id' => [$this->channelSelected('discord') ? 'required' : 'nullable', 'string', 'max:100'],
            'telegram_bot_token' => [$this->channelSelected('telegram') && ! $this->has_telegram_bot_token ? 'required' : 'nullable', 'string', 'max:500'],
            'telegram_chat_id' => [$this->channelSelected('telegram') ? 'required' : 'nullable', 'string', 'max:100'],
            'pushover_token' => [$this->channelSelected('pushover') && ! $this->has_pushover_token ? 'required' : 'nullable', 'string', 'max:500'],
            'pushover_user_key' => [$this->channelSelected('pushover') && ! $this->has_pushover_user_key ? 'required' : 'nullable', 'string', 'max:100'],
            'gotify_url' => [$this->channelSelected('gotify') ? 'required' : 'nullable', 'string', 'url', 'max:500'],
            'gotify_token' => [$this->channelSelected('gotify') && ! $this->has_gotify_token ? 'required' : 'nullable', 'string', 'max:500'],
            'webhook_url' => [$this->channelSelected('webhook') ? 'required' : 'nullable', 'string', 'url', 'max:500'],
            'webhook_secret' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function scheduleRules(): array
    {
        return [
            'schedule_name' => ['required', 'string', 'max:100'],
            'schedule_expression' => ['required', 'string', 'max:100', $this->cronRule()],
        ];
    }

    private function cronRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! CronExpression::isValidExpression($value)) {
                $fail(__('Invalid cron expression.'));
            }
        };
    }

    /**
     * Save backup configuration.
     */
    public function saveBackup(): void
    {
        $this->validate($this->backupRules());

        $backupKeyMap = [
            'working_directory' => 'backup.working_directory',
            'compression' => 'backup.compression',
            'compression_level' => 'backup.compression_level',
            'job_timeout' => 'backup.job_timeout',
            'job_tries' => 'backup.job_tries',
            'job_backoff' => 'backup.job_backoff',
            'cleanup_cron' => 'backup.cleanup_cron',
            'verify_files' => 'backup.verify_files',
            'verify_files_cron' => 'backup.verify_files_cron',
        ];

        foreach ($backupKeyMap as $property => $configKey) {
            AppConfig::set($configKey, $this->{$property});
        }
    }

    /**
     * Save notification configuration.
     */
    public function saveNotifications(): void
    {
        $this->validate($this->notificationRules());

        AppConfig::set('notifications.enabled', $this->notifications_enabled);

        // Email channel
        if ($this->channelSelected('email')) {
            AppConfig::set('notifications.mail.to', $this->mail_to ?: null);
        } else {
            AppConfig::set('notifications.mail.to', null);
            $this->mail_to = '';
        }

        // Slack channel
        if ($this->channelSelected('slack')) {
            $this->saveSensitiveField('notifications.slack.webhook_url', 'slack_webhook_url', 'has_slack_webhook_url');
        } else {
            $this->clearSensitiveField('notifications.slack.webhook_url', 'slack_webhook_url', 'has_slack_webhook_url');
        }

        // Discord channel
        if ($this->channelSelected('discord')) {
            AppConfig::set('notifications.discord.channel_id', $this->discord_channel_id ?: null);
            $this->saveSensitiveField('notifications.discord.token', 'discord_token', 'has_discord_token');
        } else {
            $this->clearSensitiveField('notifications.discord.token', 'discord_token', 'has_discord_token');
            AppConfig::set('notifications.discord.channel_id', null);
            $this->discord_channel_id = '';
        }

        // Telegram channel
        if ($this->channelSelected('telegram')) {
            AppConfig::set('notifications.telegram.chat_id', $this->telegram_chat_id ?: null);
            $this->saveSensitiveField('notifications.telegram.bot_token', 'telegram_bot_token', 'has_telegram_bot_token');
        } else {
            $this->clearSensitiveField('notifications.telegram.bot_token', 'telegram_bot_token', 'has_telegram_bot_token');
            AppConfig::set('notifications.telegram.chat_id', null);
            $this->telegram_chat_id = '';
        }

        // Pushover channel
        if ($this->channelSelected('pushover')) {
            $this->saveSensitiveField('notifications.pushover.token', 'pushover_token', 'has_pushover_token');
            $this->saveSensitiveField('notifications.pushover.user_key', 'pushover_user_key', 'has_pushover_user_key');
        } else {
            $this->clearSensitiveField('notifications.pushover.token', 'pushover_token', 'has_pushover_token');
            $this->clearSensitiveField('notifications.pushover.user_key', 'pushover_user_key', 'has_pushover_user_key');
        }

        // Gotify channel
        if ($this->channelSelected('gotify')) {
            AppConfig::set('notifications.gotify.url', $this->gotify_url ?: null);
            $this->saveSensitiveField('notifications.gotify.token', 'gotify_token', 'has_gotify_token');
        } else {
            AppConfig::set('notifications.gotify.url', null);
            $this->clearSensitiveField('notifications.gotify.token', 'gotify_token', 'has_gotify_token');
            $this->gotify_url = '';
        }

        // Webhook channel
        if ($this->channelSelected('webhook')) {
            AppConfig::set('notifications.webhook.url', $this->webhook_url ?: null);
            $this->saveSensitiveField('notifications.webhook.secret', 'webhook_secret', 'has_webhook_secret');
        } else {
            AppConfig::set('notifications.webhook.url', null);
            $this->clearSensitiveField('notifications.webhook.secret', 'webhook_secret', 'has_webhook_secret');
            $this->webhook_url = '';
        }
    }

    private function channelSelected(string $channel): bool
    {
        return in_array($channel, $this->channels);
    }

    /**
     * Persist a sensitive field to AppConfig if non-empty, then clear the form value.
     */
    private function saveSensitiveField(string $configKey, string $property, string $hasProperty): void
    {
        if ($this->{$property} !== '') {
            AppConfig::set($configKey, $this->{$property});
            $this->{$hasProperty} = true;
            $this->{$property} = '';
        }
    }

    /**
     * Null out a sensitive field in AppConfig and reset the form state.
     */
    private function clearSensitiveField(string $configKey, string $property, string $hasProperty): void
    {
        AppConfig::set($configKey, null);
        $this->{$hasProperty} = false;
        $this->{$property} = '';
    }

    public function resetScheduleFields(): void
    {
        $this->schedule_name = '';
        $this->schedule_expression = '';
        $this->resetValidation(['schedule_name', 'schedule_expression']);
    }
}
