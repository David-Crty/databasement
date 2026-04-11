<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Backup;
use App\Models\DatabaseServer;
use App\Services\Agent\AgentJobPayloadBuilder;
use App\Services\Backup\BackupJobFactory;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @tags Agent
 */
class AgentController extends Controller
{
    /**
     * Shared validation rules for agent log entries.
     *
     * @return array<string, string>
     */
    private static function logRules(): array
    {
        return [
            'logs' => 'nullable|array|max:500',
            'logs.*.timestamp' => 'required|string',
            'logs.*.type' => 'required|string|in:log,command',
            'logs.*.level' => 'nullable|string|max:20',
            'logs.*.message' => 'nullable|string|max:10000',
            'logs.*.context' => 'nullable|array',
            'logs.*.command' => 'nullable|string|max:10000',
            'logs.*.output' => 'nullable|string|max:50000',
            'logs.*.exit_code' => 'nullable|integer',
            'logs.*.duration_ms' => 'nullable|numeric',
            'logs.*.status' => 'nullable|string|max:50',
        ];
    }

    /**
     * Agent heartbeat.
     *
     * Updates the agent's last heartbeat timestamp.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        $agent->update(['last_heartbeat_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Claim the next available job.
     *
     * Atomically claims the next pending job for this agent.
     */
    public function claimJob(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        $leaseDuration = max(1, (int) config('agent.lease_duration', 300));

        $job = DB::transaction(function () use ($agent, $leaseDuration): ?AgentJob {
            /** @var AgentJob|null $job */
            $job = AgentJob::query()
                ->where(function ($query) {
                    $query->where('status', AgentJob::STATUS_PENDING)
                        ->orWhere(function ($q) {
                            $q->where('status', AgentJob::STATUS_CLAIMED)
                                ->where('lease_expires_at', '<', now());
                        });
                })
                ->whereColumn('attempts', '<', 'max_attempts')
                ->whereRelation('databaseServer', 'agent_id', $agent->id)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                return null;
            }

            $job->claim($agent, $leaseDuration);

            // Mark the backup job as running (only for backup jobs with a snapshot)
            if ($job->type === AgentJob::TYPE_BACKUP && $job->snapshot) {
                $job->snapshot->job->markRunning();
            }

            return $job;
        });

        if ($job === null) {
            return response()->json(['job' => null]);
        }

        return response()->json([
            'job' => [
                'id' => $job->id,
                'snapshot_id' => $job->snapshot_id,
                'payload' => $job->payload,
                'attempts' => $job->attempts,
                'max_attempts' => $job->max_attempts,
            ],
        ]);
    }

    /**
     * Job heartbeat.
     *
     * Extends the lease on a claimed job.
     */
    public function jobHeartbeat(Request $request, AgentJob $agentJob): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        if ($agentJob->agent_id !== $agent->id) {
            return response()->json(['message' => 'This job is not assigned to your agent.'], 403);
        }

        if (! in_array($agentJob->status, [AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])) {
            return response()->json(['message' => "Cannot heartbeat a job with status '{$agentJob->status}'."], 409);
        }

        $validated = $request->validate(self::logRules());

        $leaseDuration = max(1, (int) config('agent.lease_duration', 300));
        $agentJob->extendLease($leaseDuration);

        if (! empty($validated['logs']) && $agentJob->snapshot) {
            $backupJob = $agentJob->snapshot->job;
            $backupJob->update([
                'logs' => array_merge($backupJob->logs ?? [], $validated['logs']),
            ]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Acknowledge job completion.
     *
     * Reports that a job has been completed successfully with file metadata.
     */
    public function ack(Request $request, AgentJob $agentJob): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        if ($agentJob->agent_id !== $agent->id) {
            return response()->json(['message' => 'This job is not assigned to your agent.'], 403);
        }

        if (! in_array($agentJob->status, [AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])) {
            return response()->json(['message' => "Cannot acknowledge a job with status '{$agentJob->status}'."], 409);
        }

        if ($agentJob->type !== AgentJob::TYPE_BACKUP || ! $agentJob->snapshot) {
            return response()->json(['message' => 'Only backup jobs can be acknowledged.'], 422);
        }

        $validated = $request->validate([
            'filename' => 'required|string|max:1000',
            'file_size' => 'required|integer|min:0',
            'checksum' => 'nullable|string|max:255',
            ...self::logRules(),
        ]);

        $snapshot = $agentJob->snapshot;
        $snapshot->update([
            'filename' => $validated['filename'],
            'file_size' => $validated['file_size'],
        ]);
        $snapshot->markCompleted($validated['checksum'] ?? null);

        $backupJob = $snapshot->job;
        if (! empty($validated['logs'])) {
            $backupJob->update([
                'logs' => array_merge($backupJob->logs ?? [], $validated['logs']),
            ]);
        }

        $agentJob->markCompleted();
        $backupJob->markCompleted();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Report job failure.
     *
     * Reports that a job has failed with an error message.
     */
    public function fail(Request $request, AgentJob $agentJob): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        if ($agentJob->agent_id !== $agent->id) {
            return response()->json(['message' => 'This job is not assigned to your agent.'], 403);
        }

        if (! in_array($agentJob->status, [AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])) {
            return response()->json(['message' => "Cannot fail a job with status '{$agentJob->status}'."], 409);
        }

        $validated = $request->validate([
            'error_message' => 'required|string|max:10000',
            ...self::logRules(),
        ]);

        $agentJob->markFailed($validated['error_message']);

        // Only update backup job logs/status for backup jobs (discovery jobs have no snapshot)
        if ($agentJob->snapshot) {
            $snapshot = $agentJob->snapshot;
            $backupJob = $snapshot->job;
            if (! empty($validated['logs'])) {
                $backupJob->update([
                    'logs' => array_merge($backupJob->logs ?? [], $validated['logs']),
                ]);
            }

            $exception = new RuntimeException($validated['error_message']);
            $backupJob->markFailed($exception);

            app(NotificationService::class)->notifyBackupFailed($snapshot, $exception);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Report discovered databases from a discovery job.
     *
     * Creates backup snapshots and agent jobs for each discovered database.
     */
    public function discoveredDatabases(
        Request $request,
        AgentJob $agentJob,
        BackupJobFactory $backupJobFactory,
        AgentJobPayloadBuilder $payloadBuilder,
    ): JsonResponse {
        /** @var Agent $agent */
        $agent = $request->user();

        if ($agentJob->agent_id !== $agent->id) {
            return response()->json(['message' => 'This job is not assigned to your agent.'], 403);
        }

        if (! in_array($agentJob->status, [AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])) {
            return response()->json(['message' => "Cannot report databases for a job with status '{$agentJob->status}'."], 409);
        }

        if ($agentJob->type !== AgentJob::TYPE_DISCOVER) {
            return response()->json(['message' => 'This endpoint is only for discovery jobs.'], 422);
        }

        $validated = $request->validate([
            'databases' => 'required|array|min:1',
            'databases.*' => 'required|string|max:255|distinct',
            ...self::logRules(),
        ]);

        /** @var DatabaseServer $server */
        $server = $agentJob->databaseServer;
        $payload = $agentJob->payload;
        $method = $payload['method'] ?? 'manual';
        $triggeredByUserId = $payload['triggered_by_user_id'] ?? null;
        $backupId = $payload['backup_id'] ?? null;

        /** @var Backup|null $backup */
        $backup = $backupId !== null
            ? Backup::with(['databaseServer', 'volume'])
                ->where('id', $backupId)
                ->where('database_server_id', $server->id)
                ->first()
            : null;

        if ($backup === null) {
            return response()->json([
                'message' => 'Backup configuration not found for this discovery job.',
            ], 422);
        }

        $jobsCreated = 0;

        foreach ($validated['databases'] as $databaseName) {
            $snapshot = $backupJobFactory->createSnapshot(
                $backup,
                $databaseName,
                $method,
                $triggeredByUserId
            );

            AgentJob::create([
                'type' => AgentJob::TYPE_BACKUP,
                'database_server_id' => $server->id,
                'snapshot_id' => $snapshot->id,
                'status' => AgentJob::STATUS_PENDING,
                'payload' => $payloadBuilder->build($snapshot),
            ]);

            $jobsCreated++;
        }

        $agentJob->markCompleted();

        return response()->json([
            'status' => 'ok',
            'jobs_created' => $jobsCreated,
        ]);
    }
}
