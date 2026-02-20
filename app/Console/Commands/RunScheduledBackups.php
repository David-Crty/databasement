<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBackupJob;
use App\Models\AgentJob;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Services\Agent\AgentJobPayloadBuilder;
use App\Services\Backup\BackupJobFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunScheduledBackups extends Command
{
    protected $signature = 'backups:run {schedule : The backup schedule ID to run}';

    protected $description = 'Run scheduled backups for a given backup schedule';

    public function handle(BackupJobFactory $backupJobFactory, AgentJobPayloadBuilder $payloadBuilder): int
    {
        $scheduleId = $this->argument('schedule');

        $schedule = BackupSchedule::find($scheduleId);

        if (! $schedule) {
            $this->error("Backup schedule not found: {$scheduleId}");

            return self::FAILURE;
        }

        $backups = Backup::with(['databaseServer', 'volume'])
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
                $this->dispatch($backup, $backupJobFactory, $payloadBuilder);
            } catch (\Throwable $e) {
                $failedCount++;
                Log::error("Failed to dispatch backup job for server [{$backup->databaseServer->name}]", [
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

    private function dispatch(Backup $backup, BackupJobFactory $backupJobFactory, AgentJobPayloadBuilder $payloadBuilder): void
    {
        $server = $backup->databaseServer;

        $snapshots = $backupJobFactory->createSnapshots(
            server: $server,
            method: 'scheduled',
        );

        foreach ($snapshots as $snapshot) {
            if ($server->agent_id) {
                AgentJob::create([
                    'snapshot_id' => $snapshot->id,
                    'status' => AgentJob::STATUS_PENDING,
                    'payload' => $payloadBuilder->build($snapshot),
                ]);
            } else {
                ProcessBackupJob::dispatch($snapshot->id);
            }
        }

        $count = count($snapshots);
        $via = $server->agent_id ? 'agent' : 'queue';
        $dbInfo = $count === 1 ? '1 database' : "{$count} databases";
        $this->line("  → Dispatched backup for: {$server->name} ({$dbInfo}) via {$via}");
    }
}
