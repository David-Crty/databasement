<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentJob;
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

        $leaseDuration = config('agent.lease_duration', 300);

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
                ->whereRelation('snapshot.databaseServer', 'agent_id', $agent->id)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                return null;
            }

            $job->claim($agent, $leaseDuration);

            // Mark the backup job as running so dashboard shows correct status and duration tracks
            $job->snapshot->job->markRunning();

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

        $validated = $request->validate([
            'logs' => 'nullable|array',
        ]);

        $leaseDuration = config('agent.lease_duration', 300);
        $agentJob->extendLease($leaseDuration);

        if (! empty($validated['logs'])) {
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

        $validated = $request->validate([
            'filename' => 'required|string|max:1000',
            'file_size' => 'required|integer|min:0',
            'checksum' => 'nullable|string|max:255',
            'logs' => 'nullable|array',
        ]);

        // Update the snapshot
        $snapshot = $agentJob->snapshot;
        $snapshot->update([
            'filename' => $validated['filename'],
            'file_size' => $validated['file_size'],
        ]);
        $snapshot->markCompleted($validated['checksum'] ?? null);

        // Append logs to the backup job before marking completed
        $backupJob = $snapshot->job;
        if (! empty($validated['logs'])) {
            $backupJob->update([
                'logs' => array_merge($backupJob->logs ?? [], $validated['logs']),
            ]);
        }

        // Mark the agent job as completed
        $agentJob->markCompleted();

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

        $validated = $request->validate([
            'error_message' => 'required|string|max:10000',
            'logs' => 'nullable|array',
        ]);

        $agentJob->markFailed($validated['error_message']);

        // Append logs and mark the backup job as failed
        $snapshot = $agentJob->snapshot;
        $backupJob = $snapshot->job;
        if (! empty($validated['logs'])) {
            $backupJob->update([
                'logs' => array_merge($backupJob->logs ?? [], $validated['logs']),
            ]);
        }
        $backupJob->markFailed(new RuntimeException($validated['error_message']));

        return response()->json(['status' => 'ok']);
    }
}
