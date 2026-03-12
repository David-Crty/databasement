<?php

namespace App\Mcp\Tools;

use App\Jobs\ProcessRestoreJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Services\Backup\BackupJobFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Restore a snapshot to a target database server. This is destructive — it drops and recreates the target database. The restore runs asynchronously.')]
#[IsDestructive]
#[IsOpenWorld]
class TriggerRestoreTool extends Tool
{
    public function handle(Request $request, BackupJobFactory $backupJobFactory): Response
    {
        $validated = $request->validate([
            'snapshot_id' => 'required|string|exists:snapshots,id',
            'database_server_id' => 'required|string|exists:database_servers,id',
            'schema_name' => 'required|string|max:255',
        ]);

        /** @var Snapshot $snapshot */
        $snapshot = Snapshot::findOrFail($validated['snapshot_id']);

        /** @var DatabaseServer $targetServer */
        $targetServer = DatabaseServer::findOrFail($validated['database_server_id']);

        if (! $request->user()?->can('restore', $targetServer)) {
            return Response::error('Permission denied. You do not have permission to trigger restores.');
        }

        try {
            $restore = $backupJobFactory->createRestore(
                snapshot: $snapshot,
                targetServer: $targetServer,
                schemaName: $validated['schema_name'],
                triggeredByUserId: $request->user()->getAuthIdentifier()
            );
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        ProcessRestoreJob::dispatch($restore->id);

        return Response::text(
            "Restore started successfully!\n"
            ."Restore ID: {$restore->id}\n"
            ."Job ID: {$restore->backup_job_id}\n"
            ."Restoring snapshot {$snapshot->database_name} ({$snapshot->database_type->label()}) to {$targetServer->name} as '{$validated['schema_name']}'.\n"
            .'Use get-job-status with the job ID to track progress.'
        );
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'snapshot_id' => $schema->string()
                ->description('The ID of the snapshot to restore.')
                ->required(),

            'database_server_id' => $schema->string()
                ->description('The ID of the target database server to restore to.')
                ->required(),

            'schema_name' => $schema->string()
                ->description('The name of the database/schema to restore into on the target server.')
                ->required(),
        ];
    }
}
