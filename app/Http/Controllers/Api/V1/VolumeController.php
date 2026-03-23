<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VolumeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveVolumeRequest;
use App\Http\Resources\VolumeResource;
use App\Models\Volume;
use App\Queries\VolumeQuery;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * @tags Volumes
 */
class VolumeController extends Controller
{
    use AuthorizesRequests;

    /**
     * List all volumes.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min($request->integer('per_page', 15), 100);

        $volumes = VolumeQuery::make()->paginate($perPage);

        return VolumeResource::collection($volumes);
    }

    /**
     * Get a volume.
     */
    public function show(Volume $volume): VolumeResource
    {
        return new VolumeResource($volume);
    }

    /**
     * Create a volume.
     *
     * @response 201
     */
    public function store(SaveVolumeRequest $request): JsonResponse
    {
        $this->authorize('create', Volume::class);

        $validated = $request->validated();
        $volumeType = VolumeType::from($validated['type']);

        $validated['config'] = $volumeType->encryptSensitiveFields($validated['config']);

        $volume = Volume::create($validated);

        return (new VolumeResource($volume))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a volume.
     */
    public function update(SaveVolumeRequest $request, Volume $volume): VolumeResource
    {
        $this->authorize('update', $volume);

        $validated = $request->validated();
        $volumeType = VolumeType::from($validated['type']);

        // Encrypt sensitive fields, preserving existing encrypted values when blank
        $validated['config'] = $volumeType->encryptSensitiveFields(
            $validated['config'],
            $volume->config
        );

        $volume->update($validated);

        return new VolumeResource($volume);
    }

    /**
     * Delete a volume.
     *
     * @response 204
     */
    public function destroy(Volume $volume): Response
    {
        $this->authorize('delete', $volume);

        $volume->delete();

        return response()->noContent();
    }
}
