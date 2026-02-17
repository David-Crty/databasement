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
            ->whereRelation('job', 'status', 'completed')
            ->where('created_at', '<', $cutoffDate)
            ->get();

        if ($expiredSnapshots->isEmpty()) {
            return;
        }

        Log::info("Snapshot cleanup: Server {$serverName} (retention: {$backup->retention_days} days)");

        foreach ($expiredSnapshots as $snapshot) {
            $this->deleteSnapshot($snapshot);
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
            ->whereRelation('job', 'status', 'completed')
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

        foreach ($snapshotsToDelete as $snapshot) {
            $this->deleteSnapshot($snapshot);
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
                ->sortBy('created_at')
                ->first();

            if ($snapshotInPeriod) {
                $selected->push($snapshotInPeriod);
            }
        }

        return $selected;
    }

    private function deleteSnapshot(Snapshot $snapshot): void
    {
        $age = $snapshot->created_at->diffInDays(now());
        $database = $snapshot->database_name;

        if ($this->dryRun) {
            Log::info("Snapshot cleanup: [DRY-RUN] Would delete {$database} ({$age} days old)");
        } else {
            $snapshot->delete();
            Log::info("Snapshot cleanup: Deleted {$database} ({$age} days old)");
        }

        $this->totalDeleted++;
    }
}
