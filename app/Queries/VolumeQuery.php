<?php

namespace App\Queries;

use App\Models\Volume;
use App\Support\Formatters;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class VolumeQuery
{
    /**
     * @return QueryBuilder<Volume>
     */
    public static function make(): QueryBuilder
    {
        return QueryBuilder::for(Volume::class)
            ->allowedFilters(
                AllowedFilter::partial('name'),
                AllowedFilter::exact('type'),
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    self::applySearch($query, $value);
                }),
            )
            ->allowedSorts(
                AllowedSort::field('name'),
                AllowedSort::field('type'),
                AllowedSort::field('created_at'),
            )
            ->defaultSort('-created_at');
    }

    /**
     * Build query from manual parameters (for Livewire).
     *
     * @return Builder<Volume>
     */
    public static function buildFromParams(
        ?string $search = null,
        string $sortColumn = 'created_at',
        string $sortDirection = 'desc'
    ): Builder {
        return Volume::query()
            ->when($search, function (Builder $query) use ($search) {
                self::applySearch($query, $search);
            })
            ->orderBy($sortColumn, Formatters::sortDirection($sortDirection));
    }

    /**
     * @param  Builder<Volume>  $query
     */
    private static function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('type', 'like', "%{$search}%");
        });
    }
}
