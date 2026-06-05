<?php

namespace App\Http\Resources;

use App\Models\ScheduledRestore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScheduledRestore
 */
class ScheduledRestoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'source_server_id' => $this->source_server_id,
            'source_database_name' => $this->source_database_name,
            'target_server_id' => $this->target_server_id,
            'schema_name' => $this->schema_name,
            'backup_schedule_id' => $this->backup_schedule_id,
            'options' => $this->options,
            'enabled' => $this->enabled,
            'last_executed_at' => $this->last_executed_at,
            'last_skip_reason' => $this->last_skip_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
