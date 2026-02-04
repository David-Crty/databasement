<?php

namespace App\Livewire\Configuration;

use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\FailureNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Configuration')]
class Index extends Component
{
    /**
     * @return array<int, array{key: string, label: string, class?: string}>
     */
    public function getHeaders(): array
    {
        return [
            ['key' => 'env', 'label' => __('Environment Variable'), 'class' => 'w-56'],
            ['key' => 'value', 'label' => __('Value'), 'class' => 'w-64'],
            ['key' => 'description', 'label' => __('Description')],
        ];
    }

    /**
     * @return array<int, array{value: mixed, env: string, description: string}>
     */
    public function getAppConfig(): array
    {
        return [
            [
                'env' => 'TZ',
                'value' => config('app.timezone') ?: '-',
                'description' => __('Application timezone for dates and scheduled tasks.'),
            ],
            [
                'env' => 'TRUSTED_PROXIES',
                'value' => config('app.trusted_proxies') ?: '-',
                'description' => __('IP addresses or CIDR ranges of trusted reverse proxies. Use "*" to trust all.'),
            ],
        ];
    }

    /**
     * @return array<int, array{value: mixed, env: string, description: string}>
     */
    public function getBackupConfig(): array
    {
        return [
            [
                'env' => 'BACKUP_WORKING_DIRECTORY',
                'value' => config('backup.working_directory') ?: '-',
                'description' => __('Temporary directory for backup and restore operations.'),
            ],
            [
                'env' => 'BACKUP_COMPRESSION',
                'value' => config('backup.compression') ?: '-',
                'description' => __('Compression algorithm: "gzip", "zstd", or "encrypted".'),
            ],
            [
                'env' => 'BACKUP_COMPRESSION_LEVEL',
                'value' => config('backup.compression_level') ?: '-',
                'description' => __('Compression level: 1-9 for gzip/encrypted, 1-19 for zstd (default: 6).'),
            ],
            [
                'env' => 'BACKUP_JOB_TIMEOUT',
                'value' => config('backup.job_timeout') ?: '-',
                'description' => __('Maximum seconds a job can run.'),
            ],
            [
                'env' => 'BACKUP_JOB_TRIES',
                'value' => config('backup.job_tries') ?: '-',
                'description' => __('Number of times to attempt the job.'),
            ],
            [
                'env' => 'BACKUP_JOB_BACKOFF',
                'value' => config('backup.job_backoff') ?: '-',
                'description' => __('Seconds to wait before retrying.'),
            ],
            [
                'env' => 'BACKUP_DAILY_CRON',
                'value' => config('backup.daily_cron') ?: '-',
                'description' => __('Cron schedule for daily backups.'),
            ],
            [
                'env' => 'BACKUP_WEEKLY_CRON',
                'value' => config('backup.weekly_cron') ?: '-',
                'description' => __('Cron schedule for weekly backups.'),
            ],
            [
                'env' => 'BACKUP_CLEANUP_CRON',
                'value' => config('backup.cleanup_cron') ?: '-',
                'description' => __('Cron schedule for snapshot cleanup.'),
            ],
        ];
    }

    /**
     * @return array<int, array{value: mixed, env: string, description: string}>
     */
    public function getNotificationConfig(): array
    {
        return [
            [
                'env' => 'NOTIFICATION_ENABLED',
                'value' => config('notifications.enabled') ? 'true' : 'false',
                'description' => __('Enable failure notifications for backup and restore jobs.'),
            ],
            [
                'env' => 'NOTIFICATION_MAIL_TO',
                'value' => config('notifications.mail.to') ?: '-',
                'description' => __('Email address for failure notifications.'),
            ],
            [
                'env' => 'NOTIFICATION_SLACK_WEBHOOK_URL',
                'value' => $this->maskSensitiveValue(config('notifications.slack.webhook_url')),
                'description' => __('Slack webhook URL for failure notifications.'),
            ],
            [
                'env' => 'NOTIFICATION_DISCORD_BOT_TOKEN',
                'value' => $this->maskSensitiveValue(config('notifications.discord.token')),
                'description' => __('Discord bot token for failure notifications.'),
            ],
            [
                'env' => 'NOTIFICATION_DISCORD_CHANNEL_ID',
                'value' => config('notifications.discord.channel_id') ?: '-',
                'description' => __('Discord channel ID for failure notifications.'),
            ],
        ];
    }

    /**
     * @return array<int, array{value: mixed, env: string, description: string}>
     */
    public function getSsoConfig(): array
    {
        return [
            [
                'env' => 'OAUTH_GOOGLE_ENABLED',
                'value' => config('oauth.providers.google.enabled') ? 'true' : 'false',
                'description' => __('Enable Google OAuth authentication.'),
            ],
            [
                'env' => 'OAUTH_GITHUB_ENABLED',
                'value' => config('oauth.providers.github.enabled') ? 'true' : 'false',
                'description' => __('Enable GitHub OAuth authentication.'),
            ],
            [
                'env' => 'OAUTH_GITLAB_ENABLED',
                'value' => config('oauth.providers.gitlab.enabled') ? 'true' : 'false',
                'description' => __('Enable GitLab OAuth authentication.'),
            ],
            [
                'env' => 'OAUTH_OIDC_ENABLED',
                'value' => config('oauth.providers.oidc.enabled') ? 'true' : 'false',
                'description' => __('Enable generic OIDC authentication (Keycloak, Authentik, etc.).'),
            ],
            [
                'env' => 'OAUTH_AUTO_CREATE_USERS',
                'value' => config('oauth.auto_create_users') ? 'true' : 'false',
                'description' => __('Automatically create users on first OAuth login.'),
            ],
            [
                'env' => 'OAUTH_DEFAULT_ROLE',
                'value' => config('oauth.default_role') ?: '-',
                'description' => __('Default role for new OAuth users: viewer, member, or admin.'),
            ],
            [
                'env' => 'OAUTH_AUTO_LINK_BY_EMAIL',
                'value' => config('oauth.auto_link_by_email') ? 'true' : 'false',
                'description' => __('Link OAuth logins to existing users with matching email.'),
            ],
        ];
    }

    private function maskSensitiveValue(mixed $value): string
    {
        return $value ? '********' : '-';
    }

    public function isNotificationEnabled(): bool
    {
        return (bool) config('notifications.enabled');
    }

    public function sendTestNotification(): void
    {
        $service = app(FailureNotificationService::class);
        $routes = $service->getNotificationRoutes();

        if (empty($routes)) {
            Session::flash('notification-error', __('No notification channels configured. Please set at least one of: NOTIFICATION_MAIL_TO, NOTIFICATION_SLACK_WEBHOOK_URL, or NOTIFICATION_DISCORD_BOT_TOKEN and NOTIFICATION_DISCORD_CHANNEL_ID.'));

            return;
        }

        try {
            $server = new DatabaseServer(['name' => '[TEST] Production Database']);
            $snapshot = new Snapshot([
                'database_name' => 'app_production',
                'backup_job_id' => 'test-notification',
            ]);
            $snapshot->setRelation('databaseServer', $server);

            $exception = new \Exception('SQLSTATE[HY000] [2002] Connection refused (This is a test notification)');

            $service->notifyBackupFailed($snapshot, $exception);

            $channelNames = implode(', ', array_keys($routes));
            Session::flash('notification-success', __('Test notification sent to: :channels', ['channels' => $channelNames]));
        } catch (\Throwable $e) {
            Session::flash('notification-error', __('Failed to send test notification: :message', ['message' => $e->getMessage()]));
        }
    }

    public function render(): View
    {
        return view('livewire.configuration.index', [
            'headers' => $this->getHeaders(),
            'appConfig' => $this->getAppConfig(),
            'backupConfig' => $this->getBackupConfig(),
            'notificationConfig' => $this->getNotificationConfig(),
            'ssoConfig' => $this->getSsoConfig(),
        ]);
    }
}
