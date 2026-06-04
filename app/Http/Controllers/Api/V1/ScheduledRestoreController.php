<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveScheduledRestoreRequest;
use App\Http\Resources\ScheduledRestoreResource;
use App\Models\ScheduledRestore;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

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
     * @response 202
     */
    public function run(ScheduledRestore $scheduledRestore): JsonResponse
    {
        $this->authorize('run', $scheduledRestore);

        Artisan::call('restores:run', ['scheduledRestore' => $scheduledRestore->id]);

        return response()->json([
            'message' => __('Scheduled restore triggered.'),
        ], 202);
    }
}
