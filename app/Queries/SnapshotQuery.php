<?php

namespace App\Queries;

use App\Models\BackupJob;
use App\Models\Snapshot;
use App\Support\Formatters;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class SnapshotQuery
{
    private const RELATIONSHIPS = [
        'databaseServer',
        'backup',
        'volume',
        'triggeredBy',
        'job',
    ];

    private const ALLOWED_SORT_COLUMNS = [
        'started_at',
        'created_at',
        'file_size',
        'database_name',
        'status',
    ];

    /**
     * @return QueryBuilder<Snapshot>
     */
    public static function make(): QueryBuilder
    {
        return QueryBuilder::for(Snapshot::class)
            ->with(self::RELATIONSHIPS)
            ->allowedFilters(
                AllowedFilter::exact('database_server_id'),
                AllowedFilter::partial('database_name'),
                AllowedFilter::exact('database_type'),
                AllowedFilter::exact('method'),
                AllowedFilter::callback('status', function (Builder $query, $value) {
                    $query->whereHas('job', fn (Builder $q) => $q->whereRaw('status = ?', [$value]));
                }),
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    self::applySearch($query, $value);
                }),
            )
            ->allowedSorts(
                AllowedSort::field('started_at'),
                AllowedSort::field('created_at'),
                AllowedSort::field('file_size'),
                AllowedSort::field('database_name'),
            )
            ->defaultSort('-started_at');
    }

    /**
     * Build query from manual parameters (for Livewire).
     *
     * @return Builder<Snapshot>
     */
    public static function buildFromParams(
        ?string $search = null,
        string $statusFilter = 'all',
        ?string $serverFilter = null,
        ?string $dbTypeFilter = null,
        bool $fileMissing = false,
        string $sortColumn = 'started_at',
        string $sortDirection = 'desc'
    ): Builder {
        $query = Snapshot::query()
            ->with(self::RELATIONSHIPS);

        $query->forCurrentOrg();

        $query
            ->when($search, function (Builder $query) use ($search) {
                self::applySearch($query, $search);
            })
            ->when($statusFilter !== 'all' && $statusFilter !== '', function (Builder $query) use ($statusFilter) {
                $query->whereHas('job', fn (Builder $q) => $q->whereRaw('status = ?', [$statusFilter]));
            })
            ->when($serverFilter, function (Builder $query) use ($serverFilter) {
                $query->whereRaw('database_server_id = ?', [$serverFilter]);
            })
            ->when($dbTypeFilter, function (Builder $query) use ($dbTypeFilter) {
                $query->whereRaw('database_type = ?', [$dbTypeFilter]);
            })
            ->when($fileMissing, function (Builder $query) {
                $query->whereRaw('file_exists = ?', [false]);
            });

        $direction = Formatters::sortDirection($sortDirection);
        $sortColumn = in_array($sortColumn, self::ALLOWED_SORT_COLUMNS, true) ? $sortColumn : 'created_at';

        if ($sortColumn === 'status') {
            return $query->orderBy(
                BackupJob::select('status')->whereColumn('backup_jobs.id', 'snapshots.backup_job_id'),
                $direction,
            );
        }

        return $query->orderBy($sortColumn, $direction);
    }

    /**
     * @param  Builder<Snapshot>  $query
     */
    private static function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $q) use ($search) {
            $q->whereHas('databaseServer', function (Builder $sq) use ($search) {
                $sq->whereRaw('name LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('host LIKE ?', ["%{$search}%"]);
            })
                ->orWhere('database_name', 'like', "%{$search}%")
                ->orWhereRaw('id LIKE ?', ["%{$search}%"]);
        });
    }
}
