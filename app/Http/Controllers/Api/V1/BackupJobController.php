<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BackupJobResource;
use App\Models\BackupJob;
use App\Queries\BackupJobQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Jobs
 */
class BackupJobController extends Controller
{
    /**
     * List all jobs.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min($request->integer('per_page', 15), 100);

        $jobs = BackupJobQuery::make()->paginate($perPage);

        return BackupJobResource::collection($jobs);
    }

    /**
     * Get a job.
     *
     * Resolved here rather than by route-model binding: BackupJob has no
     * organization_id and so no global scope, and binding would hand back any
     * organization's job.
     */
    public function show(string $backupJob): BackupJobResource
    {
        $job = BackupJob::query()
            ->forCurrentOrg()
            ->with([
                'snapshot.databaseServer',
                'snapshot.triggeredBy',
                'restore.snapshot.databaseServer',
                'restore.targetServer',
                'restore.triggeredBy',
            ])
            ->findOrFail($backupJob);

        return new BackupJobResource($job);
    }
}
