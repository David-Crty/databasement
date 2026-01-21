<?php

namespace App\Queries;

use App\Models\DatabaseServer;
use App\Models\Restore;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class DatabaseServerQuery
{
    /**
     * @return QueryBuilder<DatabaseServer>
     */
    public static function make(): QueryBuilder
    {
        return QueryBuilder::for(DatabaseServer::class)
            ->with(['backup.volume'])
            ->allowedFilters([
                AllowedFilter::partial('name'),
                AllowedFilter::partial('host'),
                AllowedFilter::exact('database_type'),
                AllowedFilter::partial('description'),
            ])
            ->allowedSorts([
                AllowedSort::field('name'),
                AllowedSort::field('host'),
                AllowedSort::field('database_type'),
                AllowedSort::field('created_at'),
            ])
            ->defaultSort('-created_at');
    }

    /**
     * Build query from manual parameters (for Livewire).
     *
     * @return Builder<DatabaseServer>
     */
    public static function buildFromParams(
        ?string $search = null,
        string $sortColumn = 'created_at',
        string $sortDirection = 'desc'
    ): Builder {
        return DatabaseServer::query()
            ->with(['backup.volume'])
            ->withCount('snapshots')
            ->addSelect([
                'restores_count' => Restore::selectRaw('count(*)')
                    ->whereColumn('target_server_id', 'database_servers.id'),
            ])
            ->when($search, function (Builder $query) use ($search) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('host', 'like', "%{$search}%")
                        ->orWhere('database_type', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy($sortColumn, $sortDirection);
    }
}
