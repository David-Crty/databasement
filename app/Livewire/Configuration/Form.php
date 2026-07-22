<?php

namespace App\Livewire\Configuration;

use App\Enums\CompressionType;
use App\Facades\AppConfig;
use Cron\CronExpression;

class Form extends \Livewire\Form
{
    // Application settings
    public bool $adminer_enabled = false;

    // Backup settings
    public string $working_directory = '';

    public string $compression = '';

    public int $compression_level = 6;

    public bool $compression_multithread = false;

    public int $job_timeout = 7200;

    public int $job_tries = 3;

    public int $job_backoff = 60;

    public string $cleanup_cron = '';

    public bool $verify_files = true;

    public string $verify_files_cron = '';

    public string $post_backup_script = '';

    public string $post_restore_script = '';

    // Backup Schedule modal fields
    public string $schedule_name = '';

    public string $schedule_expression = '';

    /**
     * Keep the compression level within the selected algorithm's ceiling when
     * the algorithm changes (e.g. switching zstd → gzip drops 19 down to 9).
     */
    public function updatedCompression(string $value): void
    {
        $max = CompressionType::tryFrom($value)?->maxLevel();

        if ($max !== null && $this->compression_level > $max) {
            $this->compression_level = $max;
        }
    }

    public function loadFromConfig(): void
    {
        $this->adminer_enabled = (bool) AppConfig::get('app.adminer_enabled');
        $this->working_directory = (string) AppConfig::get('backup.working_directory');
        $this->compression = (string) AppConfig::get('backup.compression');
        $this->compression_level = (int) AppConfig::get('backup.compression_level');
        $this->compression_multithread = (bool) AppConfig::get('backup.compression_multithread');
        $this->job_timeout = (int) AppConfig::get('backup.job_timeout');
        $this->job_tries = (int) AppConfig::get('backup.job_tries');
        $this->job_backoff = (int) AppConfig::get('backup.job_backoff');
        $this->cleanup_cron = (string) AppConfig::get('backup.cleanup_cron');
        $this->verify_files = (bool) AppConfig::get('backup.verify_files');
        $this->verify_files_cron = (string) AppConfig::get('backup.verify_files_cron');
        $this->post_backup_script = (string) AppConfig::get('backup.post_backup_script');
        $this->post_restore_script = (string) AppConfig::get('backup.post_restore_script');
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationRules(): array
    {
        return [
            'adminer_enabled' => ['boolean'],
        ];
    }

    public function saveApplication(): void
    {
        $this->validate($this->applicationRules());

        AppConfig::set('app.adminer_enabled', $this->adminer_enabled);
    }

    /**
     * @return array<string, mixed>
     */
    private function backupRules(): array
    {
        $maxLevel = CompressionType::tryFrom($this->compression)?->maxLevel() ?? 19;

        return [
            'working_directory' => ['required', 'string', 'max:500', new \App\Rules\SafePath(allowAbsolute: true)],
            'compression' => ['required', 'string', 'in:gzip,zstd,encrypted'],
            'compression_level' => ['required', 'integer', 'min:1', 'max:'.$maxLevel],
            'compression_multithread' => ['boolean'],
            'job_timeout' => ['required', 'integer', 'min:60', 'max:86400'],
            'job_tries' => ['required', 'integer', 'min:1', 'max:10'],
            'job_backoff' => ['required', 'integer', 'min:0', 'max:3600'],
            'cleanup_cron' => ['required', 'string', 'max:100', $this->cronRule()],
            'verify_files' => ['boolean'],
            'verify_files_cron' => ['required', 'string', 'max:100', $this->cronRule()],
            'post_backup_script' => ['nullable', 'string', 'max:10000'],
            'post_restore_script' => ['nullable', 'string', 'max:10000'],
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
            'compression_multithread' => 'backup.compression_multithread',
            'job_timeout' => 'backup.job_timeout',
            'job_tries' => 'backup.job_tries',
            'job_backoff' => 'backup.job_backoff',
            'cleanup_cron' => 'backup.cleanup_cron',
            'verify_files' => 'backup.verify_files',
            'verify_files_cron' => 'backup.verify_files_cron',
            'post_backup_script' => 'backup.post_backup_script',
            'post_restore_script' => 'backup.post_restore_script',
        ];

        foreach ($backupKeyMap as $property => $configKey) {
            AppConfig::set($configKey, $this->{$property});
        }
    }

    public function resetScheduleFields(): void
    {
        $this->schedule_name = '';
        $this->schedule_expression = '';
        $this->resetValidation(['schedule_name', 'schedule_expression']);
    }
}
