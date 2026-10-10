<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SnapshotResource;
use App\Jobs\DeleteSnapshotsJob;
use App\Models\Snapshot;
use App\Queries\SnapshotQuery;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * @tags Snapshots
 */
class SnapshotController extends Controller
{
    use AuthorizesRequests;

    /**
     * List all snapshots.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min($request->integer('per_page', 15), 100);

        $snapshots = SnapshotQuery::make()->paginate($perPage);

        return SnapshotResource::collection($snapshots);
    }

    /**
     * Get a snapshot.
     */
    public function show(Snapshot $snapshot): SnapshotResource
    {
        $snapshot->load(['databaseServer', 'backup', 'files.volume', 'triggeredBy', 'job']);

        return new SnapshotResource($snapshot);
    }

    /**
     * Delete a snapshot.
     *
     * The deletion runs on the queue, and removes the backup files from their
     * volumes unless `keep_files` is true. A locked snapshot, or one whose backup
     * is still in progress, cannot be deleted.
     *
     * @response 202
     */
    public function destroy(Request $request, Snapshot $snapshot): Response
    {
        $this->authorize('delete', $snapshot);

        abort_if($snapshot->job->status->isInProgress(), 409, 'The backup of this snapshot is still in progress.');

        DeleteSnapshotsJob::dispatchFor(Snapshot::query()->whereKey($snapshot->id), $request->boolean('keep_files'));

        return response()->noContent(202);
    }

    /**
     * Delete snapshots in bulk.
     *
     * The deletion runs on the queue. Locked snapshots, snapshots whose backup is
     * still in progress and unknown ids are skipped; `queued` is the number of
     * snapshots that will be deleted.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $this->authorize('deleteAny', Snapshot::class);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['string'],
            'keep_files' => ['boolean'],
        ]);

        $queued = DeleteSnapshotsJob::dispatchFor(
            Snapshot::query()->whereKey($validated['ids']),
            $request->boolean('keep_files'),
        );

        return response()->json(['queued' => $queued], 202);
    }
}
