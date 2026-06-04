<?php

namespace App\Queries;

use App\Models\BackupJob;
use App\Models\Restore;
use App\Support\Formatters;
use Illuminate\Database\Eloquent\Builder;

class RestoreQuery
{
    private const RELATIONSHIPS = [
        'snapshot.databaseServer',
        'targetServer',
        'triggeredBy',
        'scheduledRestore',
        'job',
    ];

    private const ALLOWED_SORT_COLUMNS = [
        'created_at',
        'status',
    ];

    /**
     * Build query from manual parameters (for Livewire).
     *
     * @return Builder<Restore>
     */
    public static function buildFromParams(
        ?string $search = null,
        string $statusFilter = 'all',
        ?string $sourceServerFilter = null,
        ?string $targetServerFilter = null,
        ?string $dbTypeFilter = null,
        string $sortColumn = 'created_at',
        string $sortDirection = 'desc'
    ): Builder {
        // The DatabaseServer model has an OrganizationScope global scope, so
        // whereHas('targetServer') automatically filters to the current org.
        $query = Restore::query()
            ->with(self::RELATIONSHIPS)
            ->whereHas('targetServer');

        $query
            ->when($search, function (Builder $query) use ($search) {
                self::applySearch($query, $search);
            })
            ->when($statusFilter !== 'all' && $statusFilter !== '', function (Builder $query) use ($statusFilter) {
                $query->whereHas('job', fn (Builder $q) => $q->whereRaw('status = ?', [$statusFilter]));
            })
            ->when($sourceServerFilter, function (Builder $query) use ($sourceServerFilter) {
                $query->whereHas('snapshot', fn (Builder $q) => $q->whereRaw('database_server_id = ?', [$sourceServerFilter]));
            })
            ->when($targetServerFilter, function (Builder $query) use ($targetServerFilter) {
                $query->whereRaw('target_server_id = ?', [$targetServerFilter]);
            })
            ->when($dbTypeFilter, function (Builder $query) use ($dbTypeFilter) {
                $query->whereHas('snapshot', fn (Builder $q) => $q->whereRaw('database_type = ?', [$dbTypeFilter]));
            });

        $direction = Formatters::sortDirection($sortDirection);
        $sortColumn = in_array($sortColumn, self::ALLOWED_SORT_COLUMNS, true) ? $sortColumn : 'created_at';

        if ($sortColumn === 'status') {
            return $query->orderBy(
                BackupJob::select('status')->whereColumn('backup_jobs.id', 'restores.backup_job_id'),
                $direction,
            );
        }

        return $query->orderBy($sortColumn, $direction);
    }

    /**
     * @param  Builder<Restore>  $query
     */
    private static function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $q) use ($search) {
            $q->whereHas('targetServer', function (Builder $sq) use ($search) {
                $sq->whereRaw('name LIKE ?', ["%{$search}%"]);
            })
                ->orWhereHas('snapshot.databaseServer', function (Builder $sq) use ($search) {
                    $sq->whereRaw('name LIKE ?', ["%{$search}%"]);
                })
                ->orWhereHas('snapshot', function (Builder $sq) use ($search) {
                    $sq->whereRaw('database_name LIKE ?', ["%{$search}%"]);
                })
                ->orWhereRaw('schema_name LIKE ?', ["%{$search}%"])
                ->orWhereRaw('id LIKE ?', ["%{$search}%"])
                ->orWhereRaw('snapshot_id LIKE ?', ["%{$search}%"]);
        });
    }
}
