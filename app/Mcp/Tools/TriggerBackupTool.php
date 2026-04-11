<?php

namespace App\Mcp\Tools;

use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Services\Backup\TriggerBackupAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Trigger an on-demand backup for a database server. Runs the first backup configuration when no backup_id is provided. The backup runs asynchronously — use get-job-status to check progress.')]
#[IsOpenWorld]
class TriggerBackupTool extends Tool
{
    public function handle(Request $request, TriggerBackupAction $action): Response
    {
        $validated = $request->validate([
            'database_server_id' => 'required|string|exists:database_servers,id',
            'backup_id' => 'nullable|string|exists:backups,id',
        ]);

        /** @var DatabaseServer $server */
        $server = DatabaseServer::with(['backups.volume', 'backups.backupSchedule'])->findOrFail($validated['database_server_id']);

        if (! $request->user()?->can('backup', $server)) {
            return Response::error('Permission denied. You do not have permission to trigger backups.');
        }

        $backup = isset($validated['backup_id'])
            ? $server->backups->firstWhere('id', $validated['backup_id'])
            : $server->backups->first();

        if (! $backup instanceof Backup) {
            return Response::error('No backup configuration found for this database server.');
        }

        try {
            /** @var int|null $userId */
            $userId = $request->user()->getAuthIdentifier();
            $result = $action->execute($backup, $userId);
        } catch (ValidationException $e) {
            return Response::error(collect($e->errors())->flatten()->implode(' '));
        }

        $snapshots = collect($result['snapshots']);
        $message = $result['message'];

        if ($snapshots->isNotEmpty()) {
            $snapshotLines = $snapshots->map(fn ($s) => "- Snapshot: {$s->id} (Job ID: {$s->backup_job_id})");
            $message .= "\n".$snapshotLines->implode("\n");
            $message .= "\nUse get-job-status with a Job ID to track progress.";
        }

        return Response::text($message);
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'database_server_id' => $schema->string()
                ->description('The ID of the database server to back up.')
                ->required(),
            'backup_id' => $schema->string()
                ->description('Optional ID of a specific backup configuration. When omitted, the first configuration on the server is used.'),
        ];
    }
}
