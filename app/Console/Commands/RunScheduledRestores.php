<?php

namespace App\Console\Commands;

use App\Jobs\ProcessRestoreJob;
use App\Models\Restore;
use App\Models\ScheduledRestore;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\LatestSnapshotResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RunScheduledRestores extends Command
{
    protected $signature = 'restores:run {scheduledRestore : The scheduled restore ID to run}';

    protected $description = 'Run a scheduled restore for a given scheduled restore ID';

    public function handle(BackupJobFactory $backupJobFactory, LatestSnapshotResolver $resolver): int
    {
        $id = $this->argument('scheduledRestore');

        $scheduledRestore = ScheduledRestore::query()
            ->with(['sourceServer', 'targetServer'])
            ->find($id);

        if (! $scheduledRestore) {
            $this->error("Scheduled restore not found: {$id}");

            return self::FAILURE;
        }

        if (! $scheduledRestore->enabled) {
            $this->info("Scheduled restore is disabled: {$scheduledRestore->name}");
            $this->markSkipped($scheduledRestore, ScheduledRestore::SKIP_DISABLED);

            return self::SUCCESS;
        }

        if ($this->hasInflightRestore($scheduledRestore)) {
            $this->info("Skipping {$scheduledRestore->name}: previous restore still in flight.");
            $this->markSkipped($scheduledRestore, ScheduledRestore::SKIP_PREVIOUS_IN_FLIGHT);

            return self::SUCCESS;
        }

        $snapshot = $resolver->resolve($scheduledRestore);

        if (! $snapshot) {
            $this->info("No eligible snapshot for scheduled restore: {$scheduledRestore->name}");
            $this->markSkipped($scheduledRestore, ScheduledRestore::SKIP_NO_SNAPSHOT);

            return self::SUCCESS;
        }

        try {
            $restore = $backupJobFactory->createRestore(
                snapshot: $snapshot,
                targetServer: $scheduledRestore->targetServer,
                schemaName: $scheduledRestore->schema_name,
                triggeredByUserId: null,
                options: $scheduledRestore->options ?? [],
                scheduledRestoreId: $scheduledRestore->id,
            );
        } catch (ValidationException $e) {
            $errors = collect($e->errors())->flatten()->implode(' ');
            Log::error("Failed to create scheduled restore [{$scheduledRestore->name}]: {$errors}");
            $this->error("Validation failed: {$errors}");

            return self::FAILURE;
        }

        ProcessRestoreJob::dispatch($restore->id);

        $scheduledRestore->forceFill([
            'last_executed_at' => now(),
            'last_skip_reason' => null,
        ])->save();

        $this->info("Dispatched restore [{$restore->id}] from snapshot [{$snapshot->id}] for: {$scheduledRestore->name}");

        return self::SUCCESS;
    }

    private function hasInflightRestore(ScheduledRestore $scheduledRestore): bool
    {
        return Restore::query()
            ->where('scheduled_restore_id', $scheduledRestore->id)
            ->whereHas('job', fn (Builder $q) => $q->whereIn('status', ['pending', 'running']))
            ->exists();
    }

    private function markSkipped(ScheduledRestore $scheduledRestore, string $reason): void
    {
        $scheduledRestore->forceFill([
            'last_executed_at' => now(),
            'last_skip_reason' => $reason,
        ])->save();
    }
}
