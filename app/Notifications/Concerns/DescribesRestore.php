<?php

namespace App\Notifications\Concerns;

use App\Models\Restore;

/**
 * Title subject, link and fields of a notification about a restore.
 *
 * @property Restore $restore
 */
trait DescribesRestore
{
    protected function targetServerName(): string
    {
        return $this->restore->targetServer->name ?? __('Unknown');
    }

    protected function restoreUrl(): string
    {
        return route('restores.index', ['job' => $this->restore->backup_job_id]);
    }

    /**
     * @return array<string, string>
     */
    protected function restoreFields(): array
    {
        return [
            __('Target Server') => $this->targetServerName(),
            __('Target Database') => $this->restore->schema_name ?? __('Unknown'),
            __('Source Snapshot') => $this->restore->snapshot->filename ?? __('Unknown'),
        ];
    }
}
