<?php

namespace App\Livewire\Configuration;

use App\Livewire\Forms\ConfigurationForm;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\FailureNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;

#[Title('Configuration')]
class Index extends Component
{
    use Toast;

    public ConfigurationForm $form;

    public bool $showScheduleModal = false;

    public ?string $editingScheduleId = null;

    public ?string $deleteScheduleId = null;

    public bool $showDeleteScheduleModal = false;

    public function mount(): void
    {
        $this->form->loadFromConfig();
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return auth()->user()->isAdmin();
    }

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
                'env' => 'APP_DEBUG',
                'value' => config('app.debug') ? 'true' : 'false',
                'description' => __('Enable debug mode. Should be false in production.'),
            ],
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
            [
                'env' => 'OCTANE_ENABLED',
                'value' => config('octane.enabled') ? 'true' : 'false',
                'description' => __('Enable Laravel Octane for improved performance. Enabled by default in Docker.'),
            ],
            [
                'env' => 'OCTANE_WORKERS',
                'value' => (string) config('octane.workers'),
                'description' => __('Number of Octane worker processes. Each worker holds a database connection. Use "auto" for 2x CPU cores.'),
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

    public function saveBackupConfig(): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        $this->form->saveBackup();
        $this->restartScheduler();

        $this->success(__('Backup configuration saved.'), position: 'toast-bottom');
    }

    public function saveNotificationConfig(): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        $this->form->saveNotifications();

        $this->dispatch('notification-saved');
        $this->success(__('Notification configuration saved.'), position: 'toast-bottom');
    }

    public function openScheduleModal(?string $scheduleId = null): void
    {
        $this->editingScheduleId = $scheduleId;
        $this->form->resetScheduleFields();

        if ($scheduleId) {
            $schedule = BackupSchedule::findOrFail($scheduleId);
            $this->form->schedule_name = $schedule->name;
            $this->form->schedule_expression = $schedule->expression;
        }

        $this->showScheduleModal = true;
    }

    public function saveSchedule(): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        $uniqueRule = Rule::unique('backup_schedules', 'name')
            ->when($this->editingScheduleId, fn ($rule) => $rule->ignore($this->editingScheduleId));

        $rules = $this->form->scheduleRules();
        $rules['schedule_name'][] = $uniqueRule;

        $this->form->validate($rules);

        if ($this->editingScheduleId) {
            $schedule = BackupSchedule::findOrFail($this->editingScheduleId);
            $schedule->update([
                'name' => $this->form->schedule_name,
                'expression' => $this->form->schedule_expression,
            ]);
        } else {
            BackupSchedule::create([
                'name' => $this->form->schedule_name,
                'expression' => $this->form->schedule_expression,
            ]);
        }

        $this->showScheduleModal = false;
        $this->editingScheduleId = null;
        $this->form->resetScheduleFields();
        $this->restartScheduler();

        $this->success(__('Backup schedule saved.'), position: 'toast-bottom');
    }

    public function confirmDeleteSchedule(string $scheduleId): void
    {
        $this->deleteScheduleId = $scheduleId;
        $this->showDeleteScheduleModal = true;
    }

    public function deleteSchedule(): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        if (! $this->deleteScheduleId) {
            return;
        }

        $schedule = BackupSchedule::withCount('backups')->findOrFail($this->deleteScheduleId);

        if ($schedule->backups_count > 0) {
            $this->error(__('Cannot delete a schedule that is in use by database servers.'), position: 'toast-bottom');
            $this->showDeleteScheduleModal = false;
            $this->deleteScheduleId = null;

            return;
        }

        $schedule->delete();
        $this->showDeleteScheduleModal = false;
        $this->deleteScheduleId = null;
        $this->restartScheduler();

        $this->success(__('Backup schedule deleted.'), position: 'toast-bottom');
    }

    public function sendTestNotification(): void
    {
        abort_unless(auth()->user()->isAdmin(), Response::HTTP_FORBIDDEN);

        $service = app(FailureNotificationService::class);
        $routes = $service->getNotificationRoutes();

        if (empty($routes)) {
            $this->error(__('No notification channels configured. Please configure at least one notification channel.'), position: 'toast-bottom');

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
            $this->success(__('Test notification sent to: :channels', ['channels' => $channelNames]), position: 'toast-bottom');
        } catch (\Throwable $e) {
            $this->error(__('Failed to send test notification: :message', ['message' => $e->getMessage()]), position: 'toast-bottom');
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, BackupSchedule>
     */
    #[Computed]
    public function backupSchedules(): \Illuminate\Database\Eloquent\Collection
    {
        return BackupSchedule::withCount('backups')->orderBy('name')->get();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getCompressionOptions(): array
    {
        return [
            ['id' => 'gzip', 'name' => 'gzip'],
            ['id' => 'zstd', 'name' => 'zstd'],
            ['id' => 'encrypted', 'name' => 'encrypted'],
        ];
    }

    private function restartScheduler(): void
    {
        $process = new Process(['supervisorctl', 'restart', 'schedule-run']);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('Failed to restart schedule-run', [
                'exit_code' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ]);
            $this->warning(__('Saved, but scheduler restart failed. Schedule changes take effect after container restart.'), position: 'toast-bottom');
        }
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function getChannelOptions(): array
    {
        return [
            ['id' => 'email', 'name' => __('Email')],
            ['id' => 'slack', 'name' => __('Slack')],
            ['id' => 'discord', 'name' => __('Discord')],
            ['id' => 'telegram', 'name' => __('Telegram')],
            ['id' => 'pushover', 'name' => __('Pushover')],
            ['id' => 'gotify', 'name' => __('Gotify')],
            ['id' => 'webhook', 'name' => __('Webhook')],
        ];
    }

    public function render(): View
    {
        return view('livewire.configuration.index', [
            'headers' => $this->getHeaders(),
            'appConfig' => $this->getAppConfig(),
            'ssoConfig' => $this->getSsoConfig(),
            'compressionOptions' => $this->getCompressionOptions(),
            'channelOptions' => $this->getChannelOptions(),
            'backupSchedules' => $this->backupSchedules(),
        ]);
    }
}
