<?php

namespace App\Services\Agent\Runners;

use App\Enums\AgentJobType;
use App\Services\Agent\AgentApiClient;
use App\Services\Backup\DTO\RestoreConfig;
use App\Services\Backup\InMemoryBackupLogger;
use App\Services\Backup\RestoreTask;
use App\Support\FilesystemSupport;
use Closure;

class RestoreJobRunner implements AgentJobRunner
{
    public function __construct(
        private RestoreTask $restoreTask,
    ) {}

    public function type(): AgentJobType
    {
        return AgentJobType::Restore;
    }

    public function run(array $job, AgentApiClient $client, Closure $log): void
    {
        $logger = new InMemoryBackupLogger;

        try {
            $payload = $job['payload'];
            $schemaName = $payload['schema_name'] ?? '';

            $log("Processing restore job {$job['id']}: {$payload['server_name']} / {$schemaName}");

            $logger->log("Starting restore to database: {$schemaName}", 'info');

            $config = RestoreConfig::fromPayload(
                $payload, // @phpstan-ignore argument.type
                FilesystemSupport::createWorkingDirectory('restore', $job['id']),
            );

            $this->restoreTask->execute(
                $config,
                $logger,
                onProgress: fn () => $client->jobHeartbeat($job['id'], $logger->flush()),
                onCommandHeartbeat: fn (bool $running) => $client->commandHeartbeat($job['id'], $running),
            );

            $client->ack($job['id'], logs: $logger->flush());
            $log("Restore completed: {$schemaName}");
        } catch (\Throwable $e) {
            $logger->log("Restore failed: {$e->getMessage()}", 'error');
            $log("Restore failed: {$e->getMessage()}", 'error');
            $client->fail($job['id'], $e->getMessage(), $logger->flush());
        }
    }
}
