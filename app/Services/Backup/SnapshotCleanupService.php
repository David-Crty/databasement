<?php

namespace App\Services\Backup;

use App\Enums\RunKind;
use App\Models\Backup;
use App\Models\Snapshot;
use App\Support\SnapshotChainLock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SnapshotCleanupService
{
    private bool $dryRun = false;

    private int $totalDeleted = 0;

    /**
     * Run the cleanup process.
     *
     * Locked snapshots are excluded before any tier is computed, so they are
     * never deleted and never occupy a GFS keep slot.
     *
     * @return array{deleted: int, dry_run: bool}
     */
    public function run(bool $dryRun = false): array
    {
        $this->dryRun = $dryRun;
        $this->totalDeleted = 0;

        $backupsWithRetention = Backup::whereIn('retention_policy', [Backup::RETENTION_DAYS, Backup::RETENTION_GFS])
            ->with('databaseServer')
            ->get();

        if ($backupsWithRetention->isEmpty()) {
            Log::info('Snapshot cleanup: no backups with retention period configured.');

            return ['deleted' => 0, 'dry_run' => $dryRun];
        }

        foreach ($backupsWithRetention as $backup) {
            if ($backup->retention_policy === Backup::RETENTION_GFS) {
                $this->cleanupGfs($backup);
            } elseif ($backup->retention_policy === Backup::RETENTION_DAYS) {
                $this->cleanupDays($backup);
            }
        }

        $action = $dryRun ? 'would be deleted' : 'deleted';
        Log::info("Snapshot cleanup: {$this->totalDeleted} snapshot(s) {$action}.");

        return ['deleted' => $this->totalDeleted, 'dry_run' => $dryRun];
    }

    /**
     * Apply the days-based retention policy to one backup: delete its expired,
     * unlocked, completed snapshots while keeping any run that a surviving
     * later run's restore lineage still depends on.
     */
    private function cleanupDays(Backup $backup): void
    {
        if ($backup->retention_days === null) {
            return;
        }

        $cutoffDate = now()->subDays($backup->retention_days);
        $serverName = $backup->databaseServer->name ?? 'Unknown Server';

        $expiredSnapshots = Snapshot::where('backup_id', $backup->id)
            ->completed()
            ->where('locked', false)
            ->where('created_at', '<', $cutoffDate)
            ->get();

        if ($expiredSnapshots->isEmpty()) {
            return;
        }

        Log::info("Snapshot cleanup: Server {$serverName} (retention: {$backup->retention_days} days)");

        // Ids of the snapshots this pass will remove. Retention must not delete
        // an S3 chain's full anchor while a kept incremental still references it,
        // otherwise that incremental becomes unrestorable (see restore lineage).
        $deletingIds = $expiredSnapshots->pluck('id')->flip();

        foreach ($expiredSnapshots as $snapshot) {
            $this->deleteSnapshot($snapshot, $deletingIds);
        }
    }

    /**
     * Apply the GFS retention policy to one backup across its daily, weekly
     * and monthly tiers, keeping chain runs that retained snapshots depend on.
     */
    private function cleanupGfs(Backup $backup): void
    {
        $serverName = $backup->databaseServer->name ?? 'Unknown Server';

        if (empty($backup->gfs_keep_daily) && empty($backup->gfs_keep_weekly) && empty($backup->gfs_keep_monthly)) {
            Log::warning("Snapshot cleanup: Server {$serverName} - GFS policy has no tiers configured, skipping.");

            return;
        }

        $allSnapshots = Snapshot::where('backup_id', $backup->id)
            ->completed()
            ->where('locked', false)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($allSnapshots->isEmpty()) {
            return;
        }

        $snapshotsByDatabase = $allSnapshots->groupBy('database_name');
        $snapshotsToKeep = collect();

        foreach ($snapshotsByDatabase as $databaseSnapshots) {
            if ($backup->gfs_keep_daily) {
                $dailySnapshots = $databaseSnapshots->take($backup->gfs_keep_daily);
                $snapshotsToKeep = $snapshotsToKeep->merge($dailySnapshots->pluck('id'));
            }

            if ($backup->gfs_keep_weekly) {
                $weeklySnapshots = $this->selectSnapshotsForPeriod($databaseSnapshots, $backup->gfs_keep_weekly, 'week');
                $snapshotsToKeep = $snapshotsToKeep->merge($weeklySnapshots->pluck('id'));
            }

            if ($backup->gfs_keep_monthly) {
                $monthlySnapshots = $this->selectSnapshotsForPeriod($databaseSnapshots, $backup->gfs_keep_monthly, 'month');
                $snapshotsToKeep = $snapshotsToKeep->merge($monthlySnapshots->pluck('id'));
            }
        }

        $snapshotsToDelete = $allSnapshots->reject(
            fn (Snapshot $snapshot) => $snapshotsToKeep->contains($snapshot->id)
        );

        if ($snapshotsToDelete->isEmpty()) {
            return;
        }

        Log::info("Snapshot cleanup: Server {$serverName} (GFS: {$backup->gfs_keep_daily}d/{$backup->gfs_keep_weekly}w/{$backup->gfs_keep_monthly}m)");

        $deletingIds = $snapshotsToDelete->pluck('id')->flip();

        foreach ($snapshotsToDelete as $snapshot) {
            $this->deleteSnapshot($snapshot, $deletingIds);
        }
    }

    /**
     * @param  Collection<int, Snapshot>  $snapshots
     * @return Collection<int, Snapshot>
     */
    private function selectSnapshotsForPeriod(Collection $snapshots, int $periods, string $periodType): Collection
    {
        $selected = collect();
        $now = now();

        for ($i = 0; $i < $periods; $i++) {
            $periodStart = match ($periodType) {
                'week' => $now->copy()->subWeeks($i)->startOfWeek(),
                default => $now->copy()->subMonths($i)->startOfMonth(),
            };
            $periodEnd = match ($periodType) {
                'week' => $periodStart->copy()->endOfWeek(),
                default => $periodStart->copy()->endOfMonth(),
            };

            $snapshotInPeriod = $snapshots
                ->filter(fn (Snapshot $s) => $s->created_at->between($periodStart, $periodEnd))
                ->sortByDesc('created_at')
                ->first();

            if ($snapshotInPeriod) {
                $selected->push($snapshotInPeriod);
            }
        }

        return $selected;
    }

    /**
     * Delete one snapshot, keeping any run (anchor full or incremental) that a
     * later run surviving this retention pass still needs to restore. A run's
     * archive is not self-contained, so the whole lineage from the anchor full
     * up to a kept run must stay in place.
     *
     * Real deletions of S3 chain runs take the per-chain lock shared with
     * ProcessBackupJob ({@see SnapshotChainLock}) for the whole decide-and-
     * delete below. That makes the retained-descendant check and the actual
     * delete atomic against a backup job persisting a new run into the same
     * chain — without it, a run persisted between the two steps could lose the
     * anchor/prior run it was built on (the nullOnDelete FK then clears its
     * full_snapshot_id) and become unrestorable. When the chain is busy the
     * deletion is deferred to a later pass rather than risking that. Dry runs
     * never delete, so they skip the lock.
     *
     * @param  \Illuminate\Support\Collection<int, string>|\Illuminate\Support\Collection<string, string>  $deletingIds  Ids being deleted in this pass.
     */
    private function deleteSnapshot(Snapshot $snapshot, $deletingIds): void
    {
        $age = $snapshot->created_at->diffInDays(now());
        $database = $snapshot->database_name;
        $isChainRun = $snapshot->run_kind !== null;

        // Chain runs serialize their check/delete with the backup job that
        // persists new runs of the same chain; non-chain (SQL) snapshots and
        // dry runs have no lineage to protect and take no lock.
        $lock = (! $this->dryRun && $isChainRun)
            ? SnapshotChainLock::forSnapshot($snapshot, SnapshotChainLock::CLEANUP_TTL_SECONDS)
            : null;

        if ($lock !== null && ! $lock->get()) {
            Log::warning(sprintf(
                'Snapshot cleanup: Kept %s (%d days old) - a backup for this chain is in progress.',
                $database,
                $age,
            ));

            return;
        }

        try {
            // Retention is the chain's whole-chain owner and may free older
            // runs. But it must not orphan a run that this pass leaves in
            // place while at least one kept run's restore lineage still
            // depends on it — that kept run becomes unrestorable. Restore
            // overlays every archive from the anchor full up to the target, so
            // an older incremental is just as load-bearing as its anchor full
            // and is retained too.
            if ($isChainRun && $this->hasRetainedDescendant($snapshot, $deletingIds)) {
                Log::warning("Snapshot cleanup: Kept {$database} ({$age} days old) - a newer run's restore lineage depends on it.");

                return;
            }

            if ($this->dryRun) {
                Log::info("Snapshot cleanup: [DRY-RUN] Would delete {$database} ({$age} days old)");
            } else {
                // Retention-based cleanup is the whole-chain owner: it may
                // remove an older S3 run even when newer runs exist (the
                // policy decides which runs to keep). The interactive/UI
                // delete path stays guarded. The per-chain lock above means no
                // new run can appear between this check and the delete.
                $snapshot->allowOutOfOrderChainDelete = true;
                $snapshot->delete();
                Log::info("Snapshot cleanup: Deleted {$database} ({$age} days old)");
            }

            $this->totalDeleted++;
        } finally {
            if ($lock !== null) {
                $lock->release();
            }
        }
    }

    /**
     * Whether any later run that survives this retention pass has this run in
     * its restore lineage. For an anchor full that is any kept incremental of
     * the chain; for an incremental, only kept incrementals created after it.
     * Descendants that are themselves being deleted do not block it.
     *
     * @param  \Illuminate\Support\Collection<int, string>|\Illuminate\Support\Collection<string, string>  $deletingIds
     */
    private function hasRetainedDescendant(Snapshot $snapshot, $deletingIds): bool
    {
        $anchor = $snapshot->run_kind === RunKind::FULL ? $snapshot->id : $snapshot->full_snapshot_id;

        if ($anchor === null) {
            return false;
        }

        return Snapshot::query()
            ->where('database_server_id', $snapshot->database_server_id)
            ->where('database_name', $snapshot->database_name)
            ->where('full_snapshot_id', $anchor)
            ->when($snapshot->run_kind === RunKind::INCREMENTAL, function ($query) use ($snapshot) {
                /** @var \Illuminate\Database\Eloquent\Builder<Snapshot> $query */
                // Inclusive: started_at stores whole seconds, so a descendant
                // that began in the same second is still part of the kept
                // run's lineage and must not be freed.
                $query->where('started_at', '>=', $snapshot->started_at);
            })
            ->whereNotIn('id', $deletingIds->keys())
            ->exists();
    }
}
