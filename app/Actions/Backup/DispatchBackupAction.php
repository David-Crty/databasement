<?php

namespace App\Actions\Backup;

use App\Enums\AgentJobType;
use App\Jobs\ProcessBackupJob;
use App\Models\AgentJob;
use App\Models\Snapshot;
use App\Services\Agent\AgentJobPayloadBuilder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class DispatchBackupAction
{
    use AsFake, AsObject;

    public function __construct(
        private AgentJobPayloadBuilder $payloadBuilder,
    ) {}

    /**
     * Hand a created snapshot to whatever can reach its server: the server's
     * remote agent, or the app's own queue.
     */
    public function handle(Snapshot $snapshot): void
    {
        $server = $snapshot->databaseServer;

        if ($server->agent_id === null) {
            ProcessBackupJob::dispatch($snapshot->id);

            return;
        }

        AgentJob::enqueue(AgentJobType::Backup, $server->id, $this->payloadBuilder->buildBackup($snapshot), [
            'snapshot_id' => $snapshot->id,
        ]);
    }
}
