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

    public string $daily_cron = '';

    public string $weekly_cron = '';

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

    public function loadFromConfig(): void
    {
        $this->working_directory = (string) AppConfig::get('backup.working_directory');
        $this->compression = (string) AppConfig::get('backup.compression');
        $this->compression_level = (int) AppConfig::get('backup.compression_level');
        $this->job_timeout = (int) AppConfig::get('backup.job_timeout');
        $this->job_tries = (int) AppConfig::get('backup.job_tries');
        $this->job_backoff = (int) AppConfig::get('backup.job_backoff');
        $this->daily_cron = (string) AppConfig::get('backup.daily_cron');
        $this->weekly_cron = (string) AppConfig::get('backup.weekly_cron');
        $this->cleanup_cron = (string) AppConfig::get('backup.cleanup_cron');
        $this->verify_files = (bool) AppConfig::get('backup.verify_files');
        $this->verify_files_cron = (string) AppConfig::get('backup.verify_files_cron');
        $this->notifications_enabled = (bool) AppConfig::get('notifications.enabled');
        $this->mail_to = (string) AppConfig::get('notifications.mail.to');
        $this->has_slack_webhook_url = (bool) AppConfig::get('notifications.slack.webhook_url');
        $this->has_discord_token = (bool) AppConfig::get('notifications.discord.token');
        $this->discord_channel_id = (string) AppConfig::get('notifications.discord.channel_id');

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
            'daily_cron' => ['required', 'string', 'max:100', $this->cronRule()],
            'weekly_cron' => ['required', 'string', 'max:100', $this->cronRule()],
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
        $emailSelected = in_array('email', $this->channels);
        $slackSelected = in_array('slack', $this->channels);
        $discordSelected = in_array('discord', $this->channels);

        return [
            'notifications_enabled' => ['boolean'],
            'channels' => [function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->notifications_enabled && empty($value)) {
                    $fail(__('Select at least one channel when notifications are enabled.'));
                }
            }],
            'mail_to' => [$emailSelected ? 'required' : 'nullable', 'email', 'max:255'],
            'slack_webhook_url' => [$slackSelected && ! $this->has_slack_webhook_url ? 'required' : 'nullable', 'string', 'url', 'max:500'],
            'discord_token' => [$discordSelected && ! $this->has_discord_token ? 'required' : 'nullable', 'string', 'max:500'],
            'discord_channel_id' => [$discordSelected ? 'required' : 'nullable', 'string', 'max:100'],
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
            'daily_cron' => 'backup.daily_cron',
            'weekly_cron' => 'backup.weekly_cron',
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

        $emailSelected = in_array('email', $this->channels);
        $slackSelected = in_array('slack', $this->channels);
        $discordSelected = in_array('discord', $this->channels);

        AppConfig::set('notifications.enabled', $this->notifications_enabled);

        // Email channel
        if ($emailSelected) {
            AppConfig::set('notifications.mail.to', $this->mail_to ?: null);
        } else {
            AppConfig::set('notifications.mail.to', null);
            $this->mail_to = '';
        }

        // Slack channel
        if ($slackSelected) {
            if ($this->slack_webhook_url !== '') {
                AppConfig::set('notifications.slack.webhook_url', $this->slack_webhook_url);
                $this->has_slack_webhook_url = true;
                $this->slack_webhook_url = '';
            }
        } else {
            AppConfig::set('notifications.slack.webhook_url', null);
            $this->has_slack_webhook_url = false;
            $this->slack_webhook_url = '';
        }

        // Discord channel
        if ($discordSelected) {
            AppConfig::set('notifications.discord.channel_id', $this->discord_channel_id ?: null);
            if ($this->discord_token !== '') {
                AppConfig::set('notifications.discord.token', $this->discord_token);
                $this->has_discord_token = true;
                $this->discord_token = '';
            }
        } else {
            AppConfig::set('notifications.discord.token', null);
            AppConfig::set('notifications.discord.channel_id', null);
            $this->has_discord_token = false;
            $this->discord_token = '';
            $this->discord_channel_id = '';
        }
    }
}
