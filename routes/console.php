<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run daily backups (default: every day at 2:00 AM)
Schedule::command('backups:run daily')->cron(config('backup.daily_cron'));

// Run weekly backups (default: every Sunday at 3:00 AM)
Schedule::command('backups:run weekly')->cron(config('backup.weekly_cron'));

// Cleanup expired snapshots (default: every day at 4:00 AM)
Schedule::command('snapshots:cleanup')->cron(config('backup.cleanup_cron'));

// Verify snapshot files exist on volumes (default: every day at 5:00 AM)
Schedule::command('snapshots:verify-files')
    ->cron(config('backup.verify_files_cron'))
    ->when(fn () => config('backup.verify_files'));
