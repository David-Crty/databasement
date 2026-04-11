<?php

namespace App\Http\Resources;

use App\Models\Backup;
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
            'description' => $this->description,
            'backups_enabled' => $this->backups_enabled,
            'ssh_config_id' => $this->ssh_config_id,
            'agent_id' => $this->agent_id,
            'extra_config' => $this->extra_config,
            'managed_by' => $this->managed_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'backups' => $this->whenLoaded(
                'backups',
                fn () => $this->backups->map(fn (Backup $backup) => $this->transformBackup($backup))->all(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformBackup(Backup $backup): array
    {
        return [
            'id' => $backup->id,
            'backup_schedule_id' => $backup->backup_schedule_id,
            'backup_schedule' => $backup->relationLoaded('backupSchedule')
                ? [
                    'id' => $backup->backupSchedule->id,
                    'name' => $backup->backupSchedule->name,
                    'expression' => $backup->backupSchedule->expression,
                ]
                : null,
            'volume_id' => $backup->volume_id,
            'path' => $backup->path,
            'retention_policy' => $backup->retention_policy,
            'retention_days' => $backup->retention_days,
            'gfs_keep_daily' => $backup->gfs_keep_daily,
            'gfs_keep_weekly' => $backup->gfs_keep_weekly,
            'gfs_keep_monthly' => $backup->gfs_keep_monthly,
            'database_selection_mode' => $backup->database_selection_mode,
            'database_names' => $backup->database_names,
            'database_include_pattern' => $backup->database_include_pattern,
        ];
    }
}
