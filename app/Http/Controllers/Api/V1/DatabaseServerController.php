<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DatabaseServerResource;
use App\Http\Resources\SnapshotResource;
use App\Models\DatabaseServer;
use App\Queries\DatabaseServerQuery;
use App\Services\Backup\TriggerBackupAction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

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

        try {
            /** @var int|null $userId */
            $userId = auth()->id();
            $result = $action->execute($databaseServer, $userId);

            return response()->json([
                'message' => $result['message'],
                'snapshots' => SnapshotResource::collection($result['snapshots']),
            ], 202);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
