<?php

namespace App\Queries\Concerns;

use App\Models\BackupJob;
use Illuminate\Database\Eloquent\Builder;

/**
 * Snapshots and restores both carry their status on the related backup job, so
 * sorting by it orders on a correlated subquery rather than a local column.
 */
trait OrdersByJobStatus
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $foreignKey  Qualified `backup_job_id` column, e.g. `snapshots.backup_job_id`.
     * @param  'asc'|'desc'  $direction
     * @return Builder<TModel>
     */
    protected static function orderByJobStatus(Builder $query, string $foreignKey, string $direction): Builder
    {
        return $query->orderBy(
            BackupJob::select('status')->whereColumn('backup_jobs.id', $foreignKey),
            $direction,
        );
    }
}
