<?php

namespace App\Console\Commands;

use App\Models\Backup;
use App\Models\Snapshot;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CleanupExpiredSnapshots extends Command
{
    protected $signature = 'snapshots:cleanup {--dry-run : List snapshots that would be deleted without actually deleting them}';

    protected $description = 'Delete snapshots older than the configured retention period';

    private bool $dryRun = false;

    private int $totalDeleted = 0;

    public function handle(): int
    {
        $this->dryRun = $this->option('dry-run');

        if ($this->dryRun) {
            $this->info('Running in dry-run mode. No snapshots will be deleted.');
        }

        // Find all backups with retention configured (excludes 'forever' policy)
        $backupsWithRetention = Backup::where(function (Builder $query) {
            $query->where('retention_policy', Backup::RETENTION_DAYS)
                ->orWhere('retention_policy', Backup::RETENTION_GFS);
        })
            ->where('retention_policy', '!=', Backup::RETENTION_FOREVER)
            ->with('databaseServer')
            ->get();

        if ($backupsWithRetention->isEmpty()) {
            $this->info('No backups with retention period configured.');

            return self::SUCCESS;
        }

        foreach ($backupsWithRetention as $backup) {
            if ($backup->retention_policy === Backup::RETENTION_GFS) {
                $this->cleanupGfs($backup);
            } elseif ($backup->retention_policy === Backup::RETENTION_DAYS) {
                $this->cleanupDays($backup);
            }
            // RETENTION_FOREVER is excluded by the query, but skip just in case
        }

        if ($this->totalDeleted === 0) {
            $this->info('No expired snapshots found.');
        } else {
            $action = $this->dryRun ? 'would be deleted' : 'deleted';
            $this->info("{$this->totalDeleted} snapshot(s) {$action}.");
        }

        return self::SUCCESS;
    }

    /**
     * Clean up snapshots using days-based retention.
     */
    private function cleanupDays(Backup $backup): void
    {
        if ($backup->retention_days === null) {
            return;
        }

        $cutoffDate = now()->subDays($backup->retention_days);
        $serverName = $backup->databaseServer->name ?? 'Unknown Server';

        // Find completed snapshots older than retention period
        $expiredSnapshots = Snapshot::where('backup_id', $backup->id)
            ->whereHas('job', fn (Builder $q): Builder => $q->whereRaw('status = ?', ['completed']))
            ->where('created_at', '<', $cutoffDate)
            ->get();

        if ($expiredSnapshots->isEmpty()) {
            return;
        }

        $this->line("Server: {$serverName} (retention: {$backup->retention_days} days)");

        foreach ($expiredSnapshots as $snapshot) {
            $this->deleteSnapshot($snapshot);
        }
    }

    /**
     * Clean up snapshots using GFS (Grandfather-Father-Son) retention policy.
     */
    private function cleanupGfs(Backup $backup): void
    {
        $serverName = $backup->databaseServer->name ?? 'Unknown Server';

        // Guard: if all GFS tiers are null/empty, skip cleanup to avoid deleting all snapshots
        if (empty($backup->gfs_keep_daily) && empty($backup->gfs_keep_weekly) && empty($backup->gfs_keep_monthly)) {
            $this->warn("Server: {$serverName} - GFS policy has no tiers configured, skipping cleanup.");

            return;
        }

        // Get all completed snapshots for this backup, ordered by creation date (newest first)
        $allSnapshots = Snapshot::where('backup_id', $backup->id)
            ->whereHas('job', fn (Builder $q): Builder => $q->whereRaw('status = ?', ['completed']))
            ->orderBy('created_at', 'desc')
            ->get();

        if ($allSnapshots->isEmpty()) {
            return;
        }

        // Group snapshots by database_name and apply GFS retention per database
        $snapshotsByDatabase = $allSnapshots->groupBy('database_name');
        $snapshotsToKeep = collect();

        foreach ($snapshotsByDatabase as $databaseName => $databaseSnapshots) {
            // Daily tier: keep the N most recent snapshots per database
            if ($backup->gfs_keep_daily) {
                $dailySnapshots = $databaseSnapshots->take($backup->gfs_keep_daily);
                $snapshotsToKeep = $snapshotsToKeep->merge($dailySnapshots->pluck('id'));
            }

            // Weekly tier: keep 1 snapshot per week for the last N weeks per database
            if ($backup->gfs_keep_weekly) {
                $weeklySnapshots = $this->selectSnapshotsForPeriod($databaseSnapshots, $backup->gfs_keep_weekly, 'week');
                $snapshotsToKeep = $snapshotsToKeep->merge($weeklySnapshots->pluck('id'));
            }

            // Monthly tier: keep 1 snapshot per month for the last N months per database
            if ($backup->gfs_keep_monthly) {
                $monthlySnapshots = $this->selectSnapshotsForPeriod($databaseSnapshots, $backup->gfs_keep_monthly, 'month');
                $snapshotsToKeep = $snapshotsToKeep->merge($monthlySnapshots->pluck('id'));
            }
        }

        // Find snapshots to delete (not in any tier)
        $snapshotsToDelete = $allSnapshots->reject(
            fn (Snapshot $snapshot) => $snapshotsToKeep->contains($snapshot->id)
        );

        if ($snapshotsToDelete->isEmpty()) {
            return;
        }

        $this->line("Server: {$serverName} (GFS: {$backup->gfs_keep_daily}d/{$backup->gfs_keep_weekly}w/{$backup->gfs_keep_monthly}m)");

        foreach ($snapshotsToDelete as $snapshot) {
            $this->deleteSnapshot($snapshot);
        }
    }

    /**
     * Select representative snapshots for a time period (week or month).
     * Keeps the oldest snapshot from each period to maximize coverage.
     *
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

            // Find the oldest snapshot in this period (to maximize retention span)
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

    /**
     * Delete a snapshot and log the action.
     */
    private function deleteSnapshot(Snapshot $snapshot): void
    {
        $age = $snapshot->created_at->diffInDays(now());
        $database = $snapshot->database_name;

        if ($this->dryRun) {
            $this->line("  [DRY-RUN] Would delete: {$database} ({$age} days old)");
        } else {
            $snapshot->delete();
            $this->line("  → Deleted: {$database} ({$age} days old)");
        }

        $this->totalDeleted++;
    }
}
