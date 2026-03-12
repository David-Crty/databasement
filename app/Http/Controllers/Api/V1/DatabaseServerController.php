<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RestoreRequest;
use App\Http\Resources\DatabaseServerResource;
use App\Http\Resources\RestoreResource;
use App\Http\Resources\SnapshotResource;
use App\Jobs\ProcessRestoreJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Queries\DatabaseServerQuery;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\TriggerBackupAction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
        $databaseServer->load(['backup.volume', 'backup.backupSchedule']);

        return new DatabaseServerResource($databaseServer);
    }

    /**
     * Trigger a backup.
     *
     * Queues a backup job for the specified database server.
     *
     * @response 202
     */
    public function backup(DatabaseServer $databaseServer, TriggerBackupAction $action): JsonResponse
    {
        $databaseServer->load(['backup.volume', 'backup.backupSchedule']);

        $this->authorize('backup', $databaseServer);

        /** @var int|null $userId */
        $userId = auth()->id();
        $result = $action->execute($databaseServer, $userId);

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
}
