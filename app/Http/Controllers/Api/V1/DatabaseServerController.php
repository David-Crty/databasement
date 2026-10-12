<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Backup\TriggerBackupAction;
use App\Actions\DatabaseServer\CreateDatabaseServerAction;
use App\Actions\DatabaseServer\DeleteDatabaseServerAction;
use App\Actions\DatabaseServer\UpdateDatabaseServerAction;
use App\Actions\Restore\TriggerRestoreAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RestoreRequest;
use App\Http\Requests\Api\V1\SaveDatabaseServerRequest;
use App\Http\Resources\DatabaseServerResource;
use App\Http\Resources\RestoreResource;
use App\Http\Resources\SnapshotResource;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Queries\DatabaseServerQuery;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\DTO\RestoreOptions;
use App\Services\CurrentOrganization;
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
        $perPage = $this->perPage($request);

        $servers = DatabaseServerQuery::make()->paginate($perPage);

        return DatabaseServerResource::collection($servers);
    }

    /**
     * Get a database server.
     */
    public function show(DatabaseServer $databaseServer): DatabaseServerResource
    {
        $databaseServer->load(['backups.volumes', 'backups.backupSchedule']);

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
        $backups = array_key_exists('backups', $validated) ? (array) $validated['backups'] : null;
        unset($validated['backups']);

        $server = CreateDatabaseServerAction::run(app(CurrentOrganization::class)->model(), $validated, backups: $backups);

        $server->load(['backups.volumes', 'backups.backupSchedule']);

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
        $backups = array_key_exists('backups', $validated) ? (array) $validated['backups'] : null;
        unset($validated['backups']);

        UpdateDatabaseServerAction::run($databaseServer, $validated, backups: $backups);

        $databaseServer->load(['backups.volumes', 'backups.backupSchedule']);

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

        DeleteDatabaseServerAction::run($databaseServer);

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
    public function backup(Request $request, DatabaseServer $databaseServer): JsonResponse
    {
        $this->authorize('backup', $databaseServer);

        $databaseServer->load(['backups.volumes', 'backups.backupSchedule']);

        $backupId = $request->query('backup_id');
        $backup = is_array($backupId) ? null : $databaseServer->backupToTrigger($backupId);

        if ($backup === null) {
            return response()->json([
                'message' => 'No backup configuration found for this database server.',
            ], 422);
        }

        /** @var int|null $userId */
        $userId = auth()->id();
        $result = TriggerBackupAction::run($backup, $userId);

        return response()->json([
            'message' => $result->message(),
            'snapshots' => SnapshotResource::collection($result->snapshots),
        ], 202);
    }

    /**
     * Trigger a restore.
     *
     * Queues a restore job to restore a snapshot to the specified database server.
     * `options.owner_user` names the PostgreSQL role the restored database is
     * handed to, the same option the scheduled restores accept.
     *
     * @response 202
     */
    public function restore(RestoreRequest $request, DatabaseServer $databaseServer): JsonResponse
    {
        $this->authorize('restore', $databaseServer);

        /** @var Snapshot $snapshot */
        $snapshot = Snapshot::findOrFail($request->validated('snapshot_id'));

        $this->authorize('restoreFrom', $snapshot);

        /** @var int|null $userId */
        $userId = auth()->id();

        $restore = TriggerRestoreAction::run(
            snapshot: $snapshot,
            targetServer: $databaseServer,
            schemaName: $request->validated('schema_name'),
            options: new RestoreOptions(ownerUser: $request->validated('options.owner_user')),
            triggeredByUserId: $userId,
        );

        return response()->json([
            'message' => 'Restore started successfully!',
            'restore' => new RestoreResource($restore),
        ], 202);
    }
}
