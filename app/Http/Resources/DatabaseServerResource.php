<?php

namespace App\Http\Resources;

use App\Models\DatabaseServer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseServer
 */
class DatabaseServerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'host' => $this->host,
            'port' => $this->port,
            'database_type' => $this->database_type,
            'database_names' => $this->database_names,
            'database_selection_mode' => $this->database_selection_mode,
            'database_include_pattern' => $this->database_include_pattern,
            'description' => $this->description,
            'backups_enabled' => $this->backups_enabled,
            'ssh_config_id' => $this->ssh_config_id,
            'agent_id' => $this->agent_id,
            'extra_config' => $this->extra_config,
            'managed_by' => $this->managed_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'backup' => $this->whenLoaded('backup', fn () => [
                'id' => $this->backup->id,
                'backup_schedule_id' => $this->backup->backup_schedule_id,
                'backup_schedule' => $this->backup->relationLoaded('backupSchedule')
                    ? [
                        'id' => $this->backup->backupSchedule->id,
                        'name' => $this->backup->backupSchedule->name,
                        'expression' => $this->backup->backupSchedule->expression,
                    ]
                    : null,
                'volume_id' => $this->backup->volume_id,
                'path' => $this->backup->path,
                'retention_policy' => $this->backup->retention_policy,
                'retention_days' => $this->backup->retention_days,
                'gfs_keep_daily' => $this->backup->gfs_keep_daily,
                'gfs_keep_weekly' => $this->backup->gfs_keep_weekly,
                'gfs_keep_monthly' => $this->backup->gfs_keep_monthly,
            ]),
        ];
    }
}
