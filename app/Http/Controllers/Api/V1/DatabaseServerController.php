<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RestoreRequest;
use App\Http\Requests\Api\V1\SaveDatabaseServerRequest;
use App\Http\Resources\DatabaseServerResource;
use App\Http\Resources\RestoreResource;
use App\Http\Resources\SnapshotResource;
use App\Jobs\ProcessRestoreJob;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Queries\DatabaseServerQuery;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Databases\DatabaseProvider;
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
        $databaseServer->load(['backup.volume', 'backup.backupSchedule']);

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
        $backupData = $validated['backup'] ?? [];
        unset($validated['backup']);

        // Default backups_enabled to true if not provided (matches DB column default)
        if (! array_key_exists('backups_enabled', $validated)) {
            $validated['backups_enabled'] = true;
        }

        DatabaseServer::normalizeSelectionMode($validated);
        DatabaseServer::buildExtraConfig($validated);

        $server = DatabaseServer::create($validated);
        $this->syncBackupConfiguration($server, $backupData);

        $server->load(['backup.volume', 'backup.backupSchedule']);

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
        $backupData = $validated['backup'] ?? [];
        unset($validated['backup']);

        // Skip password update if blank/missing
        if (array_key_exists('password', $validated) && ($validated['password'] === '' || $validated['password'] === null)) {
            unset($validated['password']);
        }

        // Preserve current backups_enabled if not provided
        if (! array_key_exists('backups_enabled', $validated)) {
            $validated['backups_enabled'] = $databaseServer->backups_enabled;
        }

        DatabaseServer::normalizeSelectionMode($validated);
        DatabaseServer::buildExtraConfig($validated, $databaseServer->extra_config, $databaseServer->database_type->value);

        $databaseServer->update($validated);
        $this->syncBackupConfiguration($databaseServer, $backupData);

        $databaseServer->load(['backup.volume', 'backup.backupSchedule']);

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

    /**
     * @param  array<string, mixed>  $backupData
     */
    private function syncBackupConfiguration(DatabaseServer $server, array $backupData): void
    {
        if (! $server->backups_enabled || empty($backupData)) {
            return;
        }

        $retentionPolicy = $backupData['retention_policy'] ?? Backup::RETENTION_DAYS;

        $normalized = [
            'volume_id' => $backupData['volume_id'],
            'path' => ! empty($backupData['path']) ? $backupData['path'] : null,
            'backup_schedule_id' => $backupData['backup_schedule_id'],
            'retention_policy' => $retentionPolicy,
            'retention_days' => null,
            'gfs_keep_daily' => null,
            'gfs_keep_weekly' => null,
            'gfs_keep_monthly' => null,
        ];

        if ($retentionPolicy === Backup::RETENTION_DAYS) {
            $normalized['retention_days'] = $backupData['retention_days'] ?? null;
        } elseif ($retentionPolicy === Backup::RETENTION_GFS) {
            $normalized['gfs_keep_daily'] = ! empty($backupData['gfs_keep_daily']) ? $backupData['gfs_keep_daily'] : null;
            $normalized['gfs_keep_weekly'] = ! empty($backupData['gfs_keep_weekly']) ? $backupData['gfs_keep_weekly'] : null;
            $normalized['gfs_keep_monthly'] = ! empty($backupData['gfs_keep_monthly']) ? $backupData['gfs_keep_monthly'] : null;
        }

        $server->backup()->updateOrCreate(
            ['database_server_id' => $server->id],
            $normalized
        );
    }
}
