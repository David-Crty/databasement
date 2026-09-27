<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentJobType;
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
     * Atomically claims the next pending job for this agent. `job_types`
     * lists the job types the agent can run; agents that predate restore
     * support send none and are only handed backup and discovery jobs.
     */
    public function claimJob(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        $validated = $request->validate([
            'job_types' => 'nullable|array',
            'job_types.*' => 'string',
        ]);

        $jobTypes = $validated['job_types'] ?? AgentJobType::legacyValues();

        $job = DB::transaction(function () use ($agent, $jobTypes): ?AgentJob {
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
                ->whereIn('type', $jobTypes)
                ->whereRelation('databaseServer', 'agent_id', $agent->id)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                return null;
            }

            $job->claim($agent);

            $job->trackedJob()?->markRunning();

            return $job;
        });

        if ($job === null) {
            return response()->json(['job' => null]);
        }

        // Agents up to 1.8 read the job type from `payload.type`, so the
        // payload keeps carrying it alongside the top-level `type`.
        return response()->json([
            'job' => [
                'id' => $job->id,
                'type' => $job->type->value,
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
        if ($rejection = $this->rejectUnlessActive($request, $agentJob, 'heartbeat')) {
            return $rejection;
        }

        $validated = $request->validate(self::logRules());

        $agentJob->extendLease();

        $agentJob->trackedJob()?->appendLogs($validated['logs'] ?? []);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Acknowledge job completion.
     *
     * Reports that a job has been completed successfully with its result:
     * file metadata for a backup, the database list for a discovery, nothing
     * beyond logs for a restore.
     */
    public function ack(Request $request, AgentJob $agentJob): JsonResponse
    {
        if ($rejection = $this->rejectUnlessActive($request, $agentJob, 'acknowledge')) {
            return $rejection;
        }

        $handler = $agentJob->handler();

        $validated = $request->validate([
            ...$handler->completionRules(),
            ...self::logRules(),
        ]);

        $agentJob->trackedJob()?->appendLogs($validated['logs'] ?? []);

        return response()->json([
            'status' => 'ok',
            ...$handler->complete($agentJob, $validated),
        ]);
    }

    /**
     * Report job failure.
     *
     * Reports that a job has failed with an error message.
     */
    public function fail(Request $request, AgentJob $agentJob): JsonResponse
    {
        if ($rejection = $this->rejectUnlessActive($request, $agentJob, 'fail')) {
            return $rejection;
        }

        $handler = $agentJob->handler();

        $validated = $request->validate([
            'error_message' => 'required|string|max:10000',
            ...$handler->failureRules(),
            ...self::logRules(),
        ]);

        $agentJob->markFailed($validated['error_message']);

        $agentJob->trackedJob()?->appendLogs($validated['logs'] ?? []);

        $handler->fail($agentJob, new RuntimeException($validated['error_message']), $validated);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Report discovered databases from a discovery job.
     *
     * Creates backup snapshots and agent jobs for each discovered database.
     * Equivalent to acknowledging the discovery job; kept for agents up to 1.8.
     */
    public function discoveredDatabases(Request $request, AgentJob $agentJob): JsonResponse
    {
        if ($rejection = $this->rejectUnlessActive($request, $agentJob, 'report databases for')) {
            return $rejection;
        }

        if ($agentJob->type !== AgentJobType::Discover) {
            return response()->json(['message' => 'This endpoint is only for discovery jobs.'], 422);
        }

        return $this->ack($request, $agentJob);
    }

    private function rejectUnlessActive(Request $request, AgentJob $agentJob, string $action): ?JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        if ($agentJob->agent_id !== $agent->id) {
            return response()->json(['message' => 'This job is not assigned to your agent.'], 403);
        }

        if (! in_array($agentJob->status, [AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])) {
            return response()->json(['message' => "Cannot {$action} a job with status '{$agentJob->status}'."], 409);
        }

        return null;
    }
}
