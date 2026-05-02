<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RestoreRequest;
use App\Http\Requests\Api\V1\SaveDatabaseServerRequest;
use App\Http\Resources\DatabaseServerResource;
use App\Http\Resources\RestoreResource;
use App\Http\Resources\SnapshotResource;
use App\Jobs\ProcessRestoreJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Queries\DatabaseServerQuery;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\SyncBackupConfigurationsAction;
use App\Services\Backup\TriggerBackupAction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * @tags Database Servers
 */
class DatabaseServerController extends Controller
{
    use AuthorizesRequests;

    /**
     * List all database servers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min($request->integer('per_page', 15), 100);

        $servers = DatabaseServerQuery::make()->paginate($perPage);

        return DatabaseServerResource::collection($servers);
    }

    /**
     * Get a database server.
     */
    public function show(DatabaseServer $databaseServer): DatabaseServerResource
    {
        $databaseServer->load(['backups.volume', 'backups.backupSchedule']);

        return new DatabaseServerResource($databaseServer);
    }

    /**
     * Create a database server.
     *
     * @response 201
     */
    public function store(SaveDatabaseServerRequest $request): JsonResponse
    {
        $this->authorize('create', DatabaseServer::class);

        $validated = $request->validated();
        $hasBackupsPayload = array_key_exists('backups', $validated);
        $backupsPayload = $validated['backups'] ?? [];
        unset($validated['backups']);

        // Default backups_enabled to true if not provided (matches DB column default)
        if (! array_key_exists('backups_enabled', $validated)) {
            $validated['backups_enabled'] = true;
        }

        DatabaseServer::buildExtraConfig($validated);

        $server = DatabaseServer::create($validated);
        $this->syncBackupConfigurations($server, $backupsPayload, $hasBackupsPayload);

        $server->load(['backups.volume', 'backups.backupSchedule']);

        return (new DatabaseServerResource($server))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a database server.
     */
    public function update(SaveDatabaseServerRequest $request, DatabaseServer $databaseServer): DatabaseServerResource
    {
        $this->authorize('update', $databaseServer);

        $validated = $request->validated();
        $hasBackupsPayload = array_key_exists('backups', $validated);
        $backupsPayload = $validated['backups'] ?? [];
        unset($validated['backups']);

        // Skip password update if blank/missing
        if (array_key_exists('password', $validated) && ($validated['password'] === '' || $validated['password'] === null)) {
            unset($validated['password']);
        }

        // Preserve current backups_enabled if not provided
        if (! array_key_exists('backups_enabled', $validated)) {
            $validated['backups_enabled'] = $databaseServer->backups_enabled;
        }

        DatabaseServer::buildExtraConfig($validated, $databaseServer->extra_config, $databaseServer->database_type->value);

        $databaseServer->update($validated);
        $this->syncBackupConfigurations($databaseServer, $backupsPayload, $hasBackupsPayload);

        $databaseServer->load(['backups.volume', 'backups.backupSchedule']);

        return new DatabaseServerResource($databaseServer);
    }

    /**
     * Delete a database server.
     *
     * @response 204
     */
    public function destroy(DatabaseServer $databaseServer): Response
    {
        $this->authorize('delete', $databaseServer);

        $databaseServer->delete();

        return response()->noContent();
    }

    /**
     * Test connection.
     *
     * Tests the connection to the specified database server.
     */
    public function testConnection(DatabaseServer $databaseServer, DatabaseProvider $databaseProvider): JsonResponse
    {
        $this->authorize('view', $databaseServer);

        $databaseServer->load('sshConfig');

        $result = $databaseProvider->testConnectionForServer($databaseServer);

        return response()->json($result);
    }

    /**
     * Trigger a backup.
     *
     * Queues a backup job for the first backup configuration on the specified
     * database server. Use the `backup_id` query parameter to target a
     * specific configuration when the server has multiple.
     *
     * @response 202
     */
    public function backup(Request $request, DatabaseServer $databaseServer, TriggerBackupAction $action): JsonResponse
    {
        $this->authorize('backup', $databaseServer);

        $databaseServer->load(['backups.volume', 'backups.backupSchedule']);

        $backupId = $request->query('backup_id');

        $backup = $backupId !== null
            ? $databaseServer->backups->firstWhere('id', $backupId)
            : $databaseServer->backups->sortBy('id')->first();

        if ($backup === null) {
            return response()->json([
                'message' => 'No backup configuration found for this database server.',
            ], 422);
        }

        /** @var int|null $userId */
        $userId = auth()->id();
        $result = $action->execute($backup, $userId);

        return response()->json([
            'message' => $result['message'],
            'snapshots' => SnapshotResource::collection($result['snapshots']),
        ], 202);
    }

    /**
     * Trigger a restore.
     *
     * Queues a restore job to restore a snapshot to the specified database server.
     *
     * @response 202
     */
    public function restore(
        RestoreRequest $request,
        DatabaseServer $databaseServer,
        BackupJobFactory $backupJobFactory
    ): JsonResponse {
        $this->authorize('restore', $databaseServer);

        /** @var Snapshot $snapshot */
        $snapshot = Snapshot::findOrFail($request->validated('snapshot_id'));

        /** @var int|null $userId */
        $userId = auth()->id();

        $restore = $backupJobFactory->createRestore(
            snapshot: $snapshot,
            targetServer: $databaseServer,
            schemaName: $request->validated('schema_name'),
            triggeredByUserId: $userId
        );

        ProcessRestoreJob::dispatch($restore->id);

        return response()->json([
            'message' => 'Restore started successfully!',
            'restore' => new RestoreResource($restore),
        ], 202);
    }

    /**
     * @param  array<int, array<string, mixed>>  $backupsPayload
     */
    private function syncBackupConfigurations(DatabaseServer $server, array $backupsPayload, bool $hasBackupsPayload): void
    {
        if (! $hasBackupsPayload) {
            return;
        }

        app(SyncBackupConfigurationsAction::class)->execute($server, $backupsPayload);
    }
}
