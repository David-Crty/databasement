<?php

namespace App\Notifications\Concerns;

use App\Models\Snapshot;

/**
 * Link and fields of a notification about a backup's snapshot.
 *
 * @property Snapshot $snapshot
 */
trait DescribesSnapshot
{
    protected function snapshotUrl(): string
    {
        return route('snapshots.index', ['job' => $this->snapshot->backup_job_id]);
    }

    /**
     * @return array<string, string>
     */
    protected function snapshotFields(): array
    {
        return [
            __('Server') => $this->snapshot->databaseServer->name,
            __('Database') => $this->snapshot->database_name ?? __('Unknown'),
        ];
    }
}
