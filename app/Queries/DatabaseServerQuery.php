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
                $scopedUser !== null && $scopedUser->isScopedUser(),
                function (Builder $query) use ($scopedUser) {
                    $query->whereIn('id', $scopedUser->getAccessibleServerIds());

                    // Build per-server correlated filters so each database_server row
                    // counts only snapshots/restores permitted by its specific grant.
                    $accesses = $scopedUser->serverAccesses()->get();

                    $query
                        ->withCount(['snapshots' => function (Builder $q) use ($accesses) {
                            $q->where(function (Builder $inner) use ($accesses) {
                                foreach ($accesses as $access) {
                                    $inner->orWhere(function (Builder $s) use ($access) {
                                        $s->whereRaw('snapshots.database_server_id = ?', [$access->database_server_id]);
                                        if ($access->allowed_databases !== null) {
                                            $s->whereIn('database_name', $access->allowed_databases);
                                        }
                                    });
                                }
                            });
                        }])
                        ->addSelect([
                            'restores_count' => Restore::selectRaw('count(*)')
                                ->whereColumn('target_server_id', 'database_servers.id')
                                ->where(function (Builder $q) use ($accesses) {
                                    foreach ($accesses as $access) {
                                        $q->orWhere(function (Builder $s) use ($access) {
                                            $s->whereRaw('target_server_id = ?', [$access->database_server_id]);
                                            if ($access->allowed_databases !== null) {
                                                $s->whereIn('schema_name', $access->allowed_databases);
                                            }
                                        });
                                    }
                                }),
                        ]);
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
