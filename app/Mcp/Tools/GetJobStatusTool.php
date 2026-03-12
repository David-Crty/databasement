<?php

namespace App\Mcp\Tools;

use App\Models\BackupJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Check the status of a backup or restore job by its ID.')]
#[IsReadOnly]
class GetJobStatusTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'job_id' => 'required|string|exists:backup_jobs,id',
        ]);

        /** @var BackupJob $job */
        $job = BackupJob::with(['snapshot.databaseServer', 'restore.targetServer', 'restore.snapshot'])->findOrFail($validated['job_id']);

        $lines = [
            "Job ID: {$job->id}",
            "Status: {$job->status}",
        ];

        if ($job->started_at) {
            $lines[] = "Started: {$job->started_at->toDateTimeString()}";
        }

        if ($job->completed_at) {
            $lines[] = "Completed: {$job->completed_at->toDateTimeString()}";
        }

        if ($job->duration_ms !== null) {
            $lines[] = "Duration: {$job->getHumanDuration()}";
        }

        if ($job->snapshot) {
            $lines[] = 'Type: backup';
            $lines[] = "Database: {$job->snapshot->database_name} on {$job->snapshot->databaseServer->name}";
            $lines[] = "Snapshot ID: {$job->snapshot->id}";
        } elseif ($job->restore) {
            $lines[] = 'Type: restore';
            $lines[] = "Restoring: {$job->restore->snapshot->database_name} → {$job->restore->targetServer->name} as '{$job->restore->schema_name}'";
            $lines[] = "Restore ID: {$job->restore->id}";
        }

        if ($job->error_message) {
            $lines[] = "Error: {$job->error_message}";
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'job_id' => $schema->string()
                ->description('The backup job ID to check status for.')
                ->required(),
        ];
    }
}
