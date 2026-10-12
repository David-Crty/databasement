<?php

namespace App\Actions\ScheduledRestore;

use App\Actions\Restore\TriggerRestoreAction;
use App\Models\BackupJob;
use App\Models\Restore;
use App\Models\ScheduledRestore;
use App\Services\Backup\DTO\RestoreOptions;
use App\Services\Backup\DTO\ScheduledRestoreResult;
use App\Services\Backup\LatestSnapshotResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RunScheduledRestoreAction
{
    use AsFake, AsObject;

    public function __construct(
        private LatestSnapshotResolver $resolver,
    ) {}

    /**
     * Run one scheduled restore, on demand or from the scheduler.
     *
     * The `enabled` flag governs scheduling only: bootstrap/app.php (withSchedule) registers
     * enabled flows with the scheduler and re-reads that list every tick, so a
     * disabled flow never fires on its own. Running one here is always a
     * deliberate act, and leaves it disabled.
     *
     * A run whose restore is rejected (say, a snapshot its target's agent
     * cannot read) is recorded with the rejection as its skip reason, so a
     * scheduled run does not fail unseen.
     *
     * @throws ValidationException When the restore is rejected
     */
    public function handle(ScheduledRestore $scheduledRestore): ScheduledRestoreResult
    {
        $scheduledRestore->loadMissing('targetServer');

        if ($this->hasInflightRestore($scheduledRestore)) {
            return $this->markSkipped($scheduledRestore, ScheduledRestore::SKIP_PREVIOUS_IN_FLIGHT);
        }

        $snapshot = $this->resolver->resolve($scheduledRestore);

        if (! $snapshot) {
            return $this->markSkipped($scheduledRestore, ScheduledRestore::SKIP_NO_SNAPSHOT);
        }

        try {
            $restore = TriggerRestoreAction::run(
                snapshot: $snapshot,
                targetServer: $scheduledRestore->targetServer,
                schemaName: $scheduledRestore->schema_name,
                options: RestoreOptions::fromArray($scheduledRestore->options ?? []),
                scheduledRestoreId: $scheduledRestore->id,
            );
        } catch (ValidationException $e) {
            $this->markSkipped($scheduledRestore, Str::limit(collect($e->errors())->flatten()->implode(' '), 255, ''));

            throw $e;
        }

        $scheduledRestore->forceFill([
            'last_executed_at' => now(),
            'last_skip_reason' => null,
        ])->save();

        return ScheduledRestoreResult::dispatched($restore);
    }

    private function hasInflightRestore(ScheduledRestore $scheduledRestore): bool
    {
        return Restore::query()
            ->where('scheduled_restore_id', $scheduledRestore->id)
            ->whereHas('job', function (Builder $q) {
                /** @var Builder<BackupJob> $q */
                $q->inProgress();
            })
            ->exists();
    }

    private function markSkipped(ScheduledRestore $scheduledRestore, string $reason): ScheduledRestoreResult
    {
        $scheduledRestore->forceFill([
            'last_executed_at' => now(),
            'last_skip_reason' => $reason,
        ])->save();

        return ScheduledRestoreResult::skipped($reason);
    }
}
