<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Agent;
use App\Models\AgentJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait RejectsInactiveAgentJobs
{
    /**
     * Reject a report on a job that is not assigned to the requesting agent,
     * or that is no longer claimed or running.
     */
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
