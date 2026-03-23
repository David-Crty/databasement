<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\VolumeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Volume\StoreFtpVolumeRequest;
use App\Http\Requests\Api\V1\Volume\StoreLocalVolumeRequest;
use App\Http\Requests\Api\V1\Volume\StoreS3VolumeRequest;
use App\Http\Requests\Api\V1\Volume\StoreSftpVolumeRequest;
use App\Http\Requests\Api\V1\Volume\StoreVolumeRequest;
use App\Http\Resources\VolumeResource;
use App\Models\Volume;
use App\Queries\VolumeQuery;
use App\Services\VolumeConnectionTester;
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
     * Create a local volume.
     *
     * @response 201
     */
    public function storeLocal(StoreLocalVolumeRequest $request): JsonResponse
    {
        return $this->createVolume($request);
    }

    /**
     * Create an S3 volume.
     *
     * @response 201
     */
    public function storeS3(StoreS3VolumeRequest $request): JsonResponse
    {
        return $this->createVolume($request);
    }

    /**
     * Create an SFTP volume.
     *
     * @response 201
     */
    public function storeSftp(StoreSftpVolumeRequest $request): JsonResponse
    {
        return $this->createVolume($request);
    }

    /**
     * Create an FTP volume.
     *
     * @response 201
     */
    public function storeFtp(StoreFtpVolumeRequest $request): JsonResponse
    {
        return $this->createVolume($request);
    }

    /**
     * Test connection.
     *
     * Tests the connection to the specified volume by writing and reading a test file.
     */
    public function testConnection(Volume $volume, VolumeConnectionTester $tester): JsonResponse
    {
        $this->authorize('view', $volume);

        return response()->json($tester->test($volume));
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

    private function createVolume(StoreVolumeRequest $request): JsonResponse
    {
        $this->authorize('create', Volume::class);

        $validated = $request->validated();
        $volumeType = VolumeType::from($validated['type']);

        $validated['config'] = $volumeType->encryptSensitiveFields($validated['config']);

        $volume = Volume::create($validated);

        return new VolumeResource($volume)
            ->response()
            ->setStatusCode(201);
    }
}
