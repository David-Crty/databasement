<?php

namespace App\Actions\ScheduledRestore;

use App\Actions\Restore\DispatchRestoreAction;
use App\Models\BackupJob;
use App\Models\Restore;
use App\Models\ScheduledRestore;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\DTO\ScheduledRestoreResult;
use App\Services\Backup\LatestSnapshotResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RunScheduledRestoreAction
{
    use AsFake, AsObject;

    public function __construct(
        private BackupJobFactory $backupJobFactory,
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
     * @throws ValidationException
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

        $restore = $this->backupJobFactory->createRestore(
            snapshot: $snapshot,
            targetServer: $scheduledRestore->targetServer,
            schemaName: $scheduledRestore->schema_name,
            triggeredByUserId: null,
            options: $scheduledRestore->options ?? [],
            scheduledRestoreId: $scheduledRestore->id,
        );

        DispatchRestoreAction::run($restore);

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
