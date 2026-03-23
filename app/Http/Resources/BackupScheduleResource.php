<?php

namespace App\Http\Resources;

use App\Models\BackupSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BackupSchedule
 */
class BackupScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'expression' => $this->expression,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
