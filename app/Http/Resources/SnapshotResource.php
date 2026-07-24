<?php

namespace App\Http\Resources;

use App\Enums\SnapshotFileStatus;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Snapshot
 */
class SnapshotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dbMetadata = $this->getDatabaseServerMetadata();

        return [
            'id' => $this->id,
            'database_name' => $this->database_name,
            'database_type' => $this->database_type,
            'metadata' => $this->metadata,
            'filename' => $this->filename,
            'file_size' => $this->file_size,
            'file_size_human' => $this->getHumanFileSize(),
            'checksum' => $this->checksum,
            'compression_type' => $this->compression_type,
            'method' => $this->method,
            'started_at' => $this->started_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'server' => $this->whenLoaded('databaseServer', fn () => [
                'id' => $this->databaseServer->id,
                'name' => $this->databaseServer->name,
            ]),
            'volumes' => $this->whenLoaded('files', fn () => $this->files->map(fn (SnapshotFile $file) => [
                'id' => $file->volume->id,
                'name' => $file->volume->name,
                'type' => $file->volume->type,
                'status' => $file->status,
                'file_exists' => $file->file_exists,
            ])->all()),
            // Deprecated: single-volume key (first available copy) kept for
            // v1 API backward compatibility.
            'volume' => $this->whenLoaded('files', function () {
                $file = $this->files->firstWhere('status', SnapshotFileStatus::Completed) ?? $this->files->first();

                return $file === null ? null : [
                    'id' => $file->volume->id,
                    'name' => $file->volume->name,
                    'type' => $file->volume->type,
                ];
            }),
            'triggered_by' => $this->whenLoaded('triggeredBy', fn () => $this->triggeredBy ? [
                'id' => $this->triggeredBy->id,
                'name' => $this->triggeredBy->name,
            ] : null),
            'job' => $this->whenLoaded('job', fn () => [
                'id' => $this->job->id,
                'status' => $this->job->status,
                'duration' => $this->job->getHumanDuration(),
            ]),
        ];
    }
}
