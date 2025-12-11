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

            $snapshots = $backupJobFactory->createSnapshots(
                server: $server,
                method: 'scheduled',
            );

            // Dispatch a job for each snapshot (parallel execution)
            foreach ($snapshots as $snapshot) {
                ProcessBackupJob::dispatch($snapshot->id);
            }

            $count = count($snapshots);
            $dbInfo = $count === 1 ? '1 database' : "{$count} databases";
            $this->line("  → Dispatched backup for: {$server->name} ({$dbInfo})");
        }

        $this->info('All backup jobs dispatched successfully.');

        return self::SUCCESS;
    }
}
