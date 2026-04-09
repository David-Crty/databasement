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

    public function resetScheduleFields(): void
    {
        $this->schedule_name = '';
        $this->schedule_expression = '';
        $this->resetValidation(['schedule_name', 'schedule_expression']);
    }
}
