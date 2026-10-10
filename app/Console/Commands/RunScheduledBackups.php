<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Services\Backup\TriggerBackupAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunScheduledBackups extends Command
{
    protected $signature = 'backups:run {schedule : The backup schedule ID to run}';

    protected $description = 'Run scheduled backups for a given backup schedule';

    public function handle(TriggerBackupAction $triggerBackup): int
    {
        $scheduleId = $this->argument('schedule');

        $schedule = BackupSchedule::find($scheduleId);

        if (! $schedule) {
            $this->error("Backup schedule not found: {$scheduleId}");

            return self::FAILURE;
        }

        $backups = Backup::with(['databaseServer', 'volumes', 'backupSchedule'])
            ->whereRelation('databaseServer', 'backups_enabled', true)
            ->where('backup_schedule_id', $schedule->id)
            ->get();

        if ($backups->isEmpty()) {
            $this->info("No backups configured for schedule: {$schedule->name}.");

            return self::SUCCESS;
        }

        $this->info("Dispatching {$backups->count()} backup(s) for schedule: {$schedule->name}...");

        $failedCount = 0;

        foreach ($backups as $backup) {
            try {
                $this->dispatch($backup, $triggerBackup);
            } catch (\Throwable $e) {
                $failedCount++;
                Log::error("Failed to dispatch backup job for server [{$backup->databaseServer->name} / {$backup->getDisplayLabel()}]", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($failedCount > 0) {
            $this->warn("Completed with {$failedCount} failed server(s).");
        } else {
            $this->info('All backup jobs dispatched successfully.');
        }

        return self::SUCCESS;
    }

    private function dispatch(Backup $backup, TriggerBackupAction $triggerBackup): void
    {
        $server = $backup->databaseServer;
        $result = $triggerBackup->execute($backup, method: 'scheduled');

        if ($result['discovery'] === true) {
            $this->line("  → Dispatched discovery for: {$server->name} [{$backup->getDisplayLabel()}] via agent");

            return;
        }

        if ($result['discovery'] === false) {
            $this->line("  → Skipped discovery for: {$server->name} [{$backup->getDisplayLabel()}] (already in-flight)");

            return;
        }

        $count = count($result['snapshots']);
        $via = $server->agent_id ? 'agent' : 'queue';
        $dbInfo = $count === 1 ? '1 database' : "{$count} databases";
        $this->line("  → Dispatched backup for: {$server->name} [{$backup->getDisplayLabel()}] ({$dbInfo}) via {$via}");
    }
}
