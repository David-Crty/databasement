<?php

namespace App\Console\Commands;

use App\Models\ScheduledRestore;
use App\Services\Backup\RunScheduledRestoreAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RunScheduledRestores extends Command
{
    protected $signature = 'restores:run {scheduledRestore : The scheduled restore ID to run}';

    protected $description = 'Run a scheduled restore for a given scheduled restore ID';

    public function handle(RunScheduledRestoreAction $action): int
    {
        $id = $this->argument('scheduledRestore');

        $scheduledRestore = ScheduledRestore::find($id);

        if (! $scheduledRestore) {
            $this->error("Scheduled restore not found: {$id}");

            return self::FAILURE;
        }

        try {
            $result = $action->execute($scheduledRestore);
        } catch (ValidationException $e) {
            $errors = collect($e->errors())->flatten()->implode(' ');
            Log::error("Failed to create scheduled restore [{$scheduledRestore->name}]: {$errors}");
            $this->error("Validation failed: {$errors}");

            return self::FAILURE;
        }

        $restore = $result->restore;

        if ($restore === null) {
            $this->info($this->skipMessage($scheduledRestore, $result->skipReason));

            return self::SUCCESS;
        }

        $this->info("Dispatched restore [{$restore->id}] from snapshot [{$restore->snapshot_id}] for: {$scheduledRestore->name}");

        return self::SUCCESS;
    }

    private function skipMessage(ScheduledRestore $scheduledRestore, ?string $reason): string
    {
        return match ($reason) {
            ScheduledRestore::SKIP_PREVIOUS_IN_FLIGHT => "Skipping {$scheduledRestore->name}: previous restore still in flight.",
            ScheduledRestore::SKIP_NO_SNAPSHOT => "No eligible snapshot for scheduled restore: {$scheduledRestore->name}",
            default => "Skipping {$scheduledRestore->name}: {$reason}",
        };
    }
}
