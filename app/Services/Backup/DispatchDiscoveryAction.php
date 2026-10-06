<?php

namespace App\Services\Backup;

use App\Enums\AgentJobType;
use App\Models\AgentJob;
use App\Models\Backup;
use App\Services\Agent\AgentJobPayloadBuilder;
use Illuminate\Support\Facades\DB;

class DispatchDiscoveryAction
{
    public function __construct(
        private AgentJobPayloadBuilder $payloadBuilder,
    ) {}

    /**
     * Ask the agent of an agent-backed server to list the databases a backup
     * configuration covers, so a backup can be dispatched for each of them.
     *
     * @param  'manual'|'scheduled'  $method
     * @return bool False when a discovery for this configuration is already in flight
     */
    public function execute(Backup $backup, string $method, ?int $triggeredByUserId = null): bool
    {
        // Lock the backup row so the in-flight check and create are atomic;
        // concurrent dispatches for the same backup serialize and only one
        // discovery job is created (backup_id lives in the JSON payload, so
        // it cannot be deduplicated via a unique column or firstOrCreate()).
        return DB::transaction(function () use ($backup, $method, $triggeredByUserId): bool {
            Backup::whereKey($backup->id)->lockForUpdate()->first();

            $hasInflightDiscovery = AgentJob::query()
                ->where('database_server_id', $backup->database_server_id)
                ->where('type', AgentJobType::Discover)
                ->whereIn('status', [AgentJob::STATUS_PENDING, AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])
                ->get()
                ->contains(fn (AgentJob $job) => ($job->payload['backup_id'] ?? null) === $backup->id);

            if ($hasInflightDiscovery) {
                return false;
            }

            AgentJob::enqueue(
                AgentJobType::Discover,
                $backup->database_server_id,
                $this->payloadBuilder->buildDiscovery($backup, $method, $triggeredByUserId),
            );

            return true;
        });
    }
}
