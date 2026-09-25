<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveScheduledRestoreRequest;
use App\Http\Resources\ScheduledRestoreResource;
use App\Models\ScheduledRestore;
use App\Services\Backup\RunScheduledRestoreAction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * @tags Scheduled Restores
 */
class ScheduledRestoreController extends Controller
{
    use AuthorizesRequests;

    /**
     * List all scheduled restores.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(1, min($request->integer('per_page', 15), 100));

        $scheduledRestores = ScheduledRestore::query()
            ->whereHas('targetServer')
            ->orderBy('name')
            ->paginate($perPage);

        return ScheduledRestoreResource::collection($scheduledRestores);
    }

    /**
     * Get a scheduled restore.
     */
    public function show(ScheduledRestore $scheduledRestore): ScheduledRestoreResource
    {
        $this->authorize('view', $scheduledRestore);

        return new ScheduledRestoreResource($scheduledRestore);
    }

    /**
     * Create a scheduled restore.
     *
     * @response 201
     */
    public function store(SaveScheduledRestoreRequest $request): JsonResponse
    {
        $this->authorize('create', ScheduledRestore::class);

        $scheduledRestore = ScheduledRestore::create($request->validated());

        return (new ScheduledRestoreResource($scheduledRestore))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a scheduled restore.
     */
    public function update(SaveScheduledRestoreRequest $request, ScheduledRestore $scheduledRestore): ScheduledRestoreResource
    {
        $this->authorize('update', $scheduledRestore);

        $scheduledRestore->update($request->validated());

        return new ScheduledRestoreResource($scheduledRestore);
    }

    /**
     * Delete a scheduled restore.
     *
     * @response 204
     */
    public function destroy(ScheduledRestore $scheduledRestore): Response
    {
        $this->authorize('delete', $scheduledRestore);

        $scheduledRestore->delete();

        return response()->noContent();
    }

    /**
     * Run a scheduled restore immediately.
     *
     * Works on a disabled scheduled restore too: `enabled` only decides whether
     * the scheduler runs it, and a run triggered here leaves it disabled.
     *
     * @response 202
     */
    public function run(ScheduledRestore $scheduledRestore, RunScheduledRestoreAction $action): JsonResponse
    {
        $this->authorize('run', $scheduledRestore);

        $result = $action->execute($scheduledRestore);

        return response()->json([
            'message' => $result->skipReason !== null
                ? __('Scheduled restore skipped.')
                : __('Scheduled restore triggered.'),
            'restore_id' => $result->restore?->id,
            'skip_reason' => $result->skipReason,
        ], 202);
    }
}
