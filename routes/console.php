<?php

use App\Facades\AppConfig;
use App\Models\BackupSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Register backup schedules dynamically from the database
try {
    foreach (BackupSchedule::all() as $backupSchedule) {
        Schedule::command('backups:run', [$backupSchedule->id])
            ->cron($backupSchedule->expression);
    }
} catch (QueryException) {
    // Table may not exist yet (pre-migration)
}

// Cleanup expired snapshots (default: every day at 4:00 AM)
Schedule::command('snapshots:cleanup')->cron(AppConfig::get('backup.cleanup_cron'));

// Verify snapshot files exist on volumes (default: every day at 5:00 AM)
Schedule::command('snapshots:verify-files')
    ->cron(AppConfig::get('backup.verify_files_cron'))
    ->when(fn () => AppConfig::get('backup.verify_files'));
