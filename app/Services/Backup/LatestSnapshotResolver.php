<?php

namespace App\Services\Backup;

use App\Models\ScheduledRestore;
use App\Models\Scopes\OrganizationScope;
use App\Models\Snapshot;

class LatestSnapshotResolver
{
    /**
     * Resolve the latest completed snapshot eligible for a scheduled restore.
     *
     * Picks the most recent snapshot for the source server and database whose
     * job is `completed` and whose backup file still exists. Returns null when
     * no eligible snapshot is available.
     */
    public function resolve(ScheduledRestore $scheduledRestore): ?Snapshot
    {
        return Snapshot::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('database_server_id', $scheduledRestore->source_server_id)
            ->where('database_name', $scheduledRestore->source_database_name)
            ->fileExists()
            ->completed()
            ->orderByDesc('created_at')
            ->first();
    }
}
