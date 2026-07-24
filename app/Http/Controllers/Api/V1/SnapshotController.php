<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SnapshotResource;
use App\Models\Snapshot;
use App\Queries\SnapshotQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Snapshots
 */
class SnapshotController extends Controller
{
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
}
