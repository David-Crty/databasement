<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\BackupSchedule\CreateBackupScheduleAction;
use App\Actions\BackupSchedule\DeleteBackupScheduleAction;
use App\Actions\BackupSchedule\UpdateBackupScheduleAction;
use App\Exceptions\Backup\BackupScheduleInUseException;
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
        $perPage = $this->perPage($request);

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

        $schedule = CreateBackupScheduleAction::run($request->string('name')->value(), $request->string('expression')->value());

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

        return new BackupScheduleResource(UpdateBackupScheduleAction::run(
            $backupSchedule,
            $request->string('name')->value(),
            $request->string('expression')->value(),
        ));
    }

    /**
     * Delete a backup schedule.
     *
     * A schedule that backups or scheduled restores still run on cannot be
     * deleted.
     *
     * @response 204
     */
    public function destroy(BackupSchedule $backupSchedule): Response
    {
        $this->authorize('delete', $backupSchedule);

        try {
            DeleteBackupScheduleAction::run($backupSchedule);
        } catch (BackupScheduleInUseException $e) {
            abort(409, $e->getMessage());
        }

        return response()->noContent();
    }
}
