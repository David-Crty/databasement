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

        $backups = Backup::with(['databaseServer', 'volume', 'backupSchedule'])
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

    private function dispatch(Backup $backup, BackupJobFactory $backupJobFactory, AgentJobPayloadBuilder $payloadBuilder): void
    {
        $server = $backup->databaseServer;

        $snapshots = $backupJobFactory->createSnapshots(
            backup: $backup,
            method: 'scheduled',
        );

        // Agent-backed servers with all/pattern mode return empty snapshots —
        // dispatch a discovery job so the agent can list databases first.
        if (empty($snapshots) && $server->agent_id) {
            $hasInflightDiscovery = AgentJob::query()
                ->where('database_server_id', $server->id)
                ->where('type', AgentJob::TYPE_DISCOVER)
                ->whereIn('status', [AgentJob::STATUS_PENDING, AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])
                ->get()
                ->contains(fn (AgentJob $job) => ($job->payload['backup_id'] ?? null) === $backup->id);

            if ($hasInflightDiscovery) {
                $this->line("  → Skipped discovery for: {$server->name} [{$backup->getDisplayLabel()}] (already in-flight)");

                return;
            }

            AgentJob::create([
                'type' => AgentJob::TYPE_DISCOVER,
                'database_server_id' => $server->id,
                'snapshot_id' => null,
                'status' => AgentJob::STATUS_PENDING,
                'payload' => $payloadBuilder->buildDiscovery($backup, 'scheduled', null),
            ]);

            $this->line("  → Dispatched discovery for: {$server->name} [{$backup->getDisplayLabel()}] via agent");

            return;
        }

        foreach ($snapshots as $snapshot) {
            if ($server->agent_id) {
                AgentJob::create([
                    'type' => AgentJob::TYPE_BACKUP,
                    'database_server_id' => $server->id,
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
        $this->line("  → Dispatched backup for: {$server->name} [{$backup->getDisplayLabel()}] ({$dbInfo}) via {$via}");
    }
}
