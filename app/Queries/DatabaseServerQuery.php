<?php

namespace App\Queries;

use App\Models\DatabaseServer;
use App\Models\Restore;
use App\Models\User;
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
            ->with(['backups.volume', 'backups.backupSchedule', 'sshConfig', 'notificationChannels'])
            ->allowedFilters(
                AllowedFilter::partial('name'),
                AllowedFilter::partial('host'),
                AllowedFilter::exact('database_type'),
                AllowedFilter::partial('description'),
                AllowedFilter::exact('managed_by'),
            )
            ->allowedSorts(
                AllowedSort::field('name'),
                AllowedSort::field('host'),
                AllowedSort::field('database_type'),
                AllowedSort::field('created_at'),
            )
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
        string $sortDirection = 'desc',
        ?User $scopedUser = null,
    ): Builder {
        return DatabaseServer::query()
            ->with(['backups.volume', 'backups.backupSchedule', 'sshConfig', 'notificationChannels'])
            ->when(
                $scopedUser !== null,
                function (Builder $query) use ($scopedUser) {
                    $query->whereIn('id', $scopedUser->getAccessibleServerIds());

                    // Collect all allowed databases across grants; null means unrestricted on that server
                    $allAllowedDbs = $scopedUser->serverAccesses()->get()
                        ->filter(fn ($a) => $a->allowed_databases !== null)
                        ->flatMap(fn ($a) => (array) $a->allowed_databases)
                        ->unique()
                        ->values()
                        ->all();

                    if (! empty($allAllowedDbs)) {
                        $query
                            ->withCount(['snapshots' => fn (Builder $q) => $q->whereIn('database_name', $allAllowedDbs)])
                            ->addSelect([
                                'restores_count' => Restore::selectRaw('count(*)')
                                    ->whereColumn('target_server_id', 'database_servers.id')
                                    ->whereIn('schema_name', $allAllowedDbs),
                            ]);
                    } else {
                        $query
                            ->withCount('snapshots')
                            ->addSelect([
                                'restores_count' => Restore::selectRaw('count(*)')
                                    ->whereColumn('target_server_id', 'database_servers.id'),
                            ]);
                    }
                },
                fn (Builder $query) => $query
                    ->withCount('snapshots')
                    ->addSelect([
                        'restores_count' => Restore::selectRaw('count(*)')
                            ->whereColumn('target_server_id', 'database_servers.id'),
                    ])
            )
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
