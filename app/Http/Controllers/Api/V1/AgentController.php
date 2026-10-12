<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentJobType;
use App\Exceptions\Backup\JobCancelledException;
use App\Http\Controllers\Api\V1\Concerns\RejectsInactiveAgentJobs;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Services\Agent\AgentApiClient;
use App\Services\Agent\Relay\RelayNotSupportedException;
use App\Services\Agent\Relay\RelayUploadStore;
use App\Services\Agent\Relay\RelayVolumes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @tags Agent
 */
class AgentController extends Controller
{
    use RejectsInactiveAgentJobs;

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
     * Updates the agent's last heartbeat timestamp, and the version it runs
     * from the `X-Databasement-Version` and `X-Databasement-Commit` headers.
     * Agents that predate those headers are recorded with no version.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        $version = $request->header(AgentApiClient::VERSION_HEADER);
        $commit = $request->header(AgentApiClient::COMMIT_HEADER);

        $agent->update([
            'last_heartbeat_at' => now(),
            'version' => match (true) {
                $version === null => null,
                Agent::minorVersion($version) !== null && strlen($version) <= 32 => ltrim($version, 'v'),
                default => 'dev',
            },
            'commit_hash' => is_string($commit) && preg_match('/^[0-9a-f]{7,40}$/i', $commit) ? strtolower($commit) : null,
        ]);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Claim the next available job.
     *
     * Atomically claims the next pending job for this agent. `job_types`
     * lists the job types the agent can run; agents that predate restore
     * support send none and are only handed backup and discovery jobs.
     * `capabilities` lists what else the agent supports: `relay` once it can
     * transfer files through the server. A job whose volumes must be relayed
     * fails at claim when the agent cannot.
     */
    public function claimJob(Request $request, RelayVolumes $relayVolumes): JsonResponse
    {
        /** @var Agent $agent */
        $agent = $request->user();

        $validated = $request->validate([
            'job_types' => 'nullable|array',
            'job_types.*' => 'string',
            'capabilities' => 'nullable|array',
            'capabilities.*' => 'string',
        ]);

        $jobTypes = $validated['job_types'] ?? AgentJobType::legacyValues();
        $supportsRelay = in_array(RelayVolumes::CAPABILITY, $validated['capabilities'] ?? [], true);

        /** @var array{0: AgentJob|null, 1: array<string, mixed>|null, 2: RelayNotSupportedException|null} $claim */
        $claim = DB::transaction(function () use ($agent, $jobTypes, $relayVolumes, $supportsRelay): array {
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
                return [null, null, null];
            }

            $job->claim($agent);

            try {
                $job->trackedJob()?->markRunning();
            } catch (JobCancelledException) {
                $job->markFailed('Cancelled.');

                return [null, null, null];
            }

            try {
                return [$job, $relayVolumes->payloadFor($job, $agent, $supportsRelay), null];
            } catch (RelayNotSupportedException $e) {
                $job->markFailed($e->getMessage());

                return [$job, null, $e];
            }
        });

        [$job, $payload, $relayError] = $claim;

        if ($relayError !== null && $job !== null) {
            $job->handler()->fail($job, $relayError, []);
        }

        if ($job === null || $payload === null) {
            return response()->json(['job' => null]);
        }

        return response()->json([
            'job' => [
                'id' => $job->id,
                'type' => $job->type->value,
                'snapshot_id' => $job->snapshot_id,
                // Agents up to 1.8 read the job type from the payload.
                'payload' => [...$payload, 'type' => $job->type->value],
                'attempts' => $job->attempts,
                'max_attempts' => $job->max_attempts,
                // Agents resend a running command's log entry only to a
                // server that replaces it rather than appending a copy.
                'merges_logs' => true,
            ],
        ]);
    }

    /**
     * Job heartbeat.
     *
     * Extends the lease on a claimed job and merges the log entries the agent
     * recorded since its last report.
     */
    public function jobHeartbeat(Request $request, AgentJob $agentJob): JsonResponse
    {
        if ($rejection = $this->rejectUnlessActive($request, $agentJob, 'heartbeat')) {
            return $rejection;
        }

        $validated = $request->validate(self::logRules());

        $agentJob->extendLease();

        $agentJob->trackedJob()?->mergeLogs($validated['logs'] ?? []);

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

        $agentJob->trackedJob()?->mergeLogs($validated['logs'] ?? []);

        app(RelayUploadStore::class)->forget($agentJob);

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

        app(RelayUploadStore::class)->forget($agentJob);

        $agentJob->trackedJob()?->mergeLogs($validated['logs'] ?? []);

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
}
