<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Snapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SnapshotCleanupService
{
    private bool $dryRun = false;

    private int $totalDeleted = 0;

    /**
     * Run the cleanup process.
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

    private function cleanupDays(Backup $backup): void
    {
        if ($backup->retention_days === null) {
            return;
        }

        $cutoffDate = now()->subDays($backup->retention_days);
        $serverName = $backup->databaseServer->name ?? 'Unknown Server';

        $expiredSnapshots = Snapshot::where('backup_id', $backup->id)
            ->completed()
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

    private function cleanupGfs(Backup $backup): void
    {
        $serverName = $backup->databaseServer->name ?? 'Unknown Server';

        if (empty($backup->gfs_keep_daily) && empty($backup->gfs_keep_weekly) && empty($backup->gfs_keep_monthly)) {
            Log::warning("Snapshot cleanup: Server {$serverName} - GFS policy has no tiers configured, skipping.");

            return;
        }

        $allSnapshots = Snapshot::where('backup_id', $backup->id)
            ->completed()
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
     * Delete one snapshot, keeping S3 full/anchored runs while a descendant
     * incremental that survives this retention pass still references them.
     *
     * @param  \Illuminate\Support\Collection<int, string>|\Illuminate\Support\Collection<string, string>  $deletingIds  Ids being deleted in this pass.
     */
    private function deleteSnapshot(Snapshot $snapshot, $deletingIds): void
    {
        $age = $snapshot->created_at->diffInDays(now());
        $database = $snapshot->database_name;

        // Retention is the chain's whole-chain owner and may free older runs.
        // But it must not orphan a full run that this pass leaves in place while
        // at least one kept incremental depends on it — that incremental becomes
        // unrestorable. In that case the full is retained too.
        if ($snapshot->run_kind?->isFull() && $this->hasRetainedDescendant($snapshot, $deletingIds)) {
            Log::warning("Snapshot cleanup: Kept {$database} ({$age} days old) - a newer incremental snapshot depends on it.");

            return;
        }

        if ($this->dryRun) {
            Log::info("Snapshot cleanup: [DRY-RUN] Would delete {$database} ({$age} days old)");
        } else {
            // Retention-based cleanup is the whole-chain owner: it may remove an
            // older S3 run even when newer runs exist (the policy decides which
            // runs to keep). The interactive/UI delete path stays guarded.
            $snapshot->allowOutOfOrderChainDelete = true;
            $snapshot->delete();
            Log::info("Snapshot cleanup: Deleted {$database} ({$age} days old)");
        }

        $this->totalDeleted++;
    }

    /**
     * Whether any descendant incremental of a full run survives this retention
     * pass. Descendants that are themselves being deleted do not block it.
     *
     * @param  \Illuminate\Support\Collection<int, string>|\Illuminate\Support\Collection<string, string>  $deletingIds
     */
    private function hasRetainedDescendant(Snapshot $snapshot, $deletingIds): bool
    {
        return Snapshot::query()
            ->where('database_server_id', $snapshot->database_server_id)
            ->where('database_name', $snapshot->database_name)
            ->where('full_snapshot_id', $snapshot->id)
            ->whereNotIn('id', $deletingIds->keys())
            ->exists();
    }
}
