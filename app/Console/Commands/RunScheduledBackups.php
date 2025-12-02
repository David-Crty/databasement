<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBackupJob;
use App\Models\Backup;
use App\Services\Backup\BackupJobFactory;
use Illuminate\Console\Command;

class RunScheduledBackups extends Command
{
    protected $signature = 'backups:run {recurrence : The recurrence type to run (daily, weekly)}';

    protected $description = 'Run scheduled backups based on recurrence type';

    public function handle(BackupJobFactory $backupJobFactory): int
    {
        $recurrence = $this->argument('recurrence');

        if (! in_array($recurrence, [Backup::RECURRENCE_DAILY, Backup::RECURRENCE_WEEKLY])) {
            $this->error("Invalid recurrence type: {$recurrence}. Must be 'daily' or 'weekly'.");

            return self::FAILURE;
        }

        $backups = Backup::with(['databaseServer', 'volume'])
            ->where('recurrence', $recurrence)
            ->get();

        if ($backups->isEmpty()) {
            $this->info("No {$recurrence} backups configured.");

            return self::SUCCESS;
        }

        $this->info("Dispatching {$backups->count()} {$recurrence} backup(s)...");

        foreach ($backups as $backup) {
            $server = $backup->databaseServer;

            $snapshot = $backupJobFactory->createBackupJob(
                server: $server,
                method: 'scheduled',
            );

            ProcessBackupJob::dispatch($snapshot->id);

            $this->line("  → Dispatched backup for: {$server->name}");
        }

        $this->info('All backup jobs dispatched successfully.');

        return self::SUCCESS;
    }
}
