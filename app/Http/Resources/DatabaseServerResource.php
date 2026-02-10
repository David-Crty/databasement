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
            'backup_all_databases' => $this->backup_all_databases,
            'description' => $this->description,
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
            ]),
        ];
    }
}
