<?php

namespace App\Queries;

use App\Models\Snapshot;
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

    /**
     * @return QueryBuilder<Snapshot>
     */
    public static function make(): QueryBuilder
    {
        return QueryBuilder::for(Snapshot::class)
            ->with(self::RELATIONSHIPS)
            ->allowedFilters([
                AllowedFilter::partial('database_name'),
                AllowedFilter::partial('database_host'),
                AllowedFilter::exact('database_type'),
                AllowedFilter::exact('method'),
                AllowedFilter::callback('status', function (Builder $query, $value) {
                    $query->whereHas('job', fn (Builder $q) => $q->where('status', $value));
                }),
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    self::applySearch($query, $value);
                }),
            ])
            ->allowedSorts([
                AllowedSort::field('started_at'),
                AllowedSort::field('created_at'),
                AllowedSort::field('file_size'),
                AllowedSort::field('database_name'),
            ])
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
        string $sortColumn = 'started_at',
        string $sortDirection = 'desc'
    ): Builder {
        return Snapshot::query()
            ->with(self::RELATIONSHIPS)
            ->when($search, function (Builder $query) use ($search) {
                self::applySearch($query, $search);
            })
            ->when($statusFilter !== 'all', function (Builder $query) use ($statusFilter) {
                $query->whereHas('job', fn (Builder $q) => $q->where('status', $statusFilter));
            })
            ->orderBy($sortColumn, $sortDirection);
    }

    /**
     * @param  Builder<Snapshot>  $query
     */
    private static function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $q) use ($search) {
            $q->whereHas('databaseServer', function (Builder $sq) use ($search) {
                $sq->where('name', 'like', "%{$search}%");
            })
                ->orWhere('database_name', 'like', "%{$search}%")
                ->orWhere('database_host', 'like', "%{$search}%");
        });
    }
}
