<?php

namespace App\Livewire\Configuration;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    /**
     * @return array<string, array{value: mixed, env: string, description: string}>
     */
    public function getAppConfig(): array
    {
        return [
            'timezone' => [
                'value' => config('app.timezone'),
                'env' => 'TZ',
                'description' => __('Application timezone for dates and scheduled tasks.'),
            ],
            'trusted_proxies' => [
                'value' => config('app.trusted_proxies') ?: '(none)',
                'env' => 'TRUSTED_PROXIES',
                'description' => __('IP addresses or CIDR ranges of trusted reverse proxies. Use "*" to trust all.'),
            ],
        ];
    }

    /**
     * @return array<string, array{value: mixed, env: string, description: string}>
     */
    public function getBackupConfig(): array
    {
        return [
            'working_directory' => [
                'value' => config('backup.working_directory'),
                'env' => 'BACKUP_WORKING_DIRECTORY',
                'description' => __('Temporary directory for backup and restore operations.'),
            ],
            'compression' => [
                'value' => config('backup.compression'),
                'env' => 'BACKUP_COMPRESSION',
                'description' => __('Compression algorithm: "gzip", "zstd", or "encrypted".'),
            ],
            'compression_level' => [
                'value' => config('backup.compression_level'),
                'env' => 'BACKUP_COMPRESSION_LEVEL',
                'description' => __('Compression level: 1-9 for gzip/encrypted, 1-19 for zstd (default: 6).'),
            ],
            'mysql_cli_type' => [
                'value' => config('backup.mysql_cli_type'),
                'env' => 'MYSQL_CLI_TYPE',
                'description' => __('MySQL CLI type: "mariadb" or "mysql".'),
            ],
            'job_timeout' => [
                'value' => config('backup.job_timeout'),
                'env' => 'BACKUP_JOB_TIMEOUT',
                'description' => __('Maximum seconds a job can run.'),
            ],
            'job_tries' => [
                'value' => config('backup.job_tries'),
                'env' => 'BACKUP_JOB_TRIES',
                'description' => __('Number of times to attempt the job.'),
            ],
            'job_backoff' => [
                'value' => config('backup.job_backoff'),
                'env' => 'BACKUP_JOB_BACKOFF',
                'description' => __('Seconds to wait before retrying.'),
            ],
            'daily_cron' => [
                'value' => config('backup.daily_cron'),
                'env' => 'BACKUP_DAILY_CRON',
                'description' => __('Cron schedule for daily backups.'),
            ],
            'weekly_cron' => [
                'value' => config('backup.weekly_cron'),
                'env' => 'BACKUP_WEEKLY_CRON',
                'description' => __('Cron schedule for weekly backups.'),
            ],
            'cleanup_cron' => [
                'value' => config('backup.cleanup_cron'),
                'env' => 'BACKUP_CLEANUP_CRON',
                'description' => __('Cron schedule for snapshot cleanup.'),
            ],
        ];
    }

    public function render(): View
    {
        return view('livewire.configuration.index', [
            'appConfig' => $this->getAppConfig(),
            'backupConfig' => $this->getBackupConfig(),
        ])->layout('components.layouts.app', ['title' => __('Configuration')]);
    }
}
