<?php

namespace App\Actions\Snapshot;

use App\Exceptions\Backup\SnapshotFilesNotDeletedException;
use App\Models\Backup;
use App\Models\Snapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class ApplyRetentionPoliciesAction
{
    use AsFake, AsObject;

    /**
     * Delete the snapshots every backup's retention policy (days or GFS) no
     * longer keeps, and return how many were deleted or handed to an agent.
     *
     * Locked snapshots are excluded before any tier is computed, so they are
     * never deleted and never occupy a GFS keep slot. So are snapshots
     * already being deleted, such as those waiting for their agent. A snapshot
     * that fails to delete is logged, left in place and not counted.
     */
    public function handle(bool $dryRun = false): int
    {
        $backupsWithRetention = Backup::whereIn('retention_policy', [Backup::RETENTION_DAYS, Backup::RETENTION_GFS])
            ->with('databaseServer')
            ->get();

        if ($backupsWithRetention->isEmpty()) {
            Log::info('Snapshot cleanup: no backups with retention period configured.');

            return 0;
        }

        $totalDeleted = 0;

        foreach ($backupsWithRetention as $backup) {
            $expiredSnapshots = match ($backup->retention_policy) {
                Backup::RETENTION_GFS => $this->expiredByGfs($backup),
                default => $this->expiredByDays($backup),
            };

            foreach ($expiredSnapshots as $snapshot) {
                if ($this->deleteSnapshot($snapshot, $dryRun)) {
                    $totalDeleted++;
                }
            }
        }

        $outcome = $dryRun ? 'would be deleted' : 'deleted';
        Log::info("Snapshot cleanup: {$totalDeleted} snapshot(s) {$outcome}.");

        return $totalDeleted;
    }

    /**
     * @return Collection<int, Snapshot>
     */
    private function expiredByDays(Backup $backup): Collection
    {
        if ($backup->retention_days === null) {
            return collect();
        }

        $cutoffDate = now()->subDays($backup->retention_days);
        $serverName = $backup->databaseServer->name ?? 'Unknown Server';

        $expiredSnapshots = Snapshot::where('backup_id', $backup->id)
            ->completed()
            ->where('locked', false)
            ->where('deleting', false)
            ->where('created_at', '<', $cutoffDate)
            ->get();

        if ($expiredSnapshots->isNotEmpty()) {
            Log::info("Snapshot cleanup: Server {$serverName} (retention: {$backup->retention_days} days)");
        }

        return $expiredSnapshots;
    }

    /**
     * @return Collection<int, Snapshot>
     */
    private function expiredByGfs(Backup $backup): Collection
    {
        $serverName = $backup->databaseServer->name ?? 'Unknown Server';

        if (empty($backup->gfs_keep_daily) && empty($backup->gfs_keep_weekly) && empty($backup->gfs_keep_monthly)) {
            Log::warning("Snapshot cleanup: Server {$serverName} - GFS policy has no tiers configured, skipping.");

            return collect();
        }

        $allSnapshots = Snapshot::where('backup_id', $backup->id)
            ->completed()
            ->where('locked', false)
            ->where('deleting', false)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($allSnapshots->isEmpty()) {
            return $allSnapshots;
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

        if ($snapshotsToDelete->isNotEmpty()) {
            Log::info("Snapshot cleanup: Server {$serverName} (GFS: {$backup->gfs_keep_daily}d/{$backup->gfs_keep_weekly}w/{$backup->gfs_keep_monthly}m)");
        }

        return $snapshotsToDelete;
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
     * Returns whether the snapshot counts as deleted (or would be, in a dry run).
     */
    private function deleteSnapshot(Snapshot $snapshot, bool $dryRun): bool
    {
        $age = $snapshot->created_at->diffInDays(now());
        $database = $snapshot->database_name;

        if ($dryRun) {
            Log::info("Snapshot cleanup: [DRY-RUN] Would delete {$database} ({$age} days old)");

            return true;
        }

        try {
            $deleted = DeleteSnapshotAction::run($snapshot);
        } catch (SnapshotFilesNotDeletedException $e) {
            Log::warning("Snapshot cleanup: {$e->getMessage()} It will be retried on the next run.");

            return false;
        } catch (Throwable $e) {
            report($e);
            Log::error("Snapshot cleanup: Failed to delete {$database}: {$e->getMessage()}");

            return false;
        }

        Log::info($deleted
            ? "Snapshot cleanup: Deleted {$database} ({$age} days old)"
            : "Snapshot cleanup: Handed {$database} ({$age} days old) to its agent for deletion");

        return true;
    }
}
