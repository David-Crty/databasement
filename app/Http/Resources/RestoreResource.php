<?php

namespace App\Http\Resources;

use App\Models\Restore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Restore
 */
class RestoreResource extends JsonResource
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
            'schema_name' => $this->schema_name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'snapshot' => $this->whenLoaded('snapshot', fn () => [
                'id' => $this->snapshot->id,
                'database_name' => $this->snapshot->database_name,
                'database_type' => $this->snapshot->database_type,
            ]),
            'target_server' => $this->whenLoaded('targetServer', fn () => [
                'id' => $this->targetServer->id,
                'name' => $this->targetServer->name,
            ]),
            'job' => $this->whenLoaded('job', fn () => [
                'id' => $this->job->id,
                'status' => $this->job->status,
            ]),
        ];
    }
}
