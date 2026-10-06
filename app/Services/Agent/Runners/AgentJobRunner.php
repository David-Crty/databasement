<?php

namespace App\Services\Agent\Runners;

use App\Enums\AgentJobType;
use App\Services\Agent\AgentApiClient;
use Closure;

/**
 * Agent-side execution of one job type: run the work order a claim handed
 * over and report the outcome. The app-side counterpart is an AgentJobHandler.
 */
interface AgentJobRunner
{
    public function type(): AgentJobType;

    /**
     * Run a claimed job and report its outcome, including failures, to the server.
     *
     * @param  array{id: string, payload: array<string, mixed>, merges_logs?: bool}  $job
     * @param  Closure(string, string=): void  $log  Writes a line to the agent's console output
     */
    public function run(array $job, AgentApiClient $client, Closure $log): void;
}
