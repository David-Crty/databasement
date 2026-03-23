<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveBackupScheduleRequest;
use App\Http\Resources\BackupScheduleResource;
use App\Models\BackupSchedule;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * @tags Backup Schedules
 */
class BackupScheduleController extends Controller
{
    use AuthorizesRequests;

    /**
     * List all backup schedules.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min($request->integer('per_page', 15), 100);

        $schedules = BackupSchedule::query()
            ->orderBy('name')
            ->paginate($perPage);

        return BackupScheduleResource::collection($schedules);
    }

    /**
     * Get a backup schedule.
     */
    public function show(BackupSchedule $backupSchedule): BackupScheduleResource
    {
        return new BackupScheduleResource($backupSchedule);
    }

    /**
     * Create a backup schedule.
     *
     * @response 201
     */
    public function store(SaveBackupScheduleRequest $request): JsonResponse
    {
        $this->authorize('create', BackupSchedule::class);

        $schedule = BackupSchedule::create($request->validated());

        return (new BackupScheduleResource($schedule))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a backup schedule.
     */
    public function update(SaveBackupScheduleRequest $request, BackupSchedule $backupSchedule): BackupScheduleResource
    {
        $this->authorize('update', $backupSchedule);

        $backupSchedule->update($request->validated());

        return new BackupScheduleResource($backupSchedule);
    }

    /**
     * Delete a backup schedule.
     *
     * @response 204
     */
    public function destroy(BackupSchedule $backupSchedule): Response
    {
        $this->authorize('delete', $backupSchedule);

        $backupSchedule->delete();

        return response()->noContent();
    }
}
