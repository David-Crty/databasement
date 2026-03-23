<?php

namespace App\Http\Resources;

use App\Models\Volume;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Volume
 */
class VolumeResource extends JsonResource
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
            'type' => $this->type,
            'config' => $this->getSafeConfig(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
