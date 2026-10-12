<?php

namespace App\Services\Agent\Runners;

use App\Enums\AgentJobType;
use App\Exceptions\Backup\JobRevokedException;
use App\Services\Agent\AgentApiClient;
use App\Services\Agent\AgentJobLogger;
use App\Services\Backup\DTO\RestoreConfig;
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
        $logger = new AgentJobLogger($client, $job['id'], $job['merges_logs'] ?? false);

        try {
            $payload = $job['payload'];
            $schemaName = $payload['schema_name'] ?? '';

            $log("Processing restore job {$job['id']}: {$payload['server_name']} / {$schemaName}");

            $logger->log("Starting restore to database: {$schemaName}", 'info');

            $config = RestoreConfig::fromPayload(
                $payload, // @phpstan-ignore argument.type
                FilesystemSupport::createWorkingDirectory('restore', $job['id']),
            );

            $this->restoreTask->execute($config, $logger);

            $client->ack($job['id'], logs: $logger->unsentLogs());
            $log("Restore completed: {$schemaName}");
        } catch (JobRevokedException $e) {
            $log("Restore job {$job['id']} stopped: {$e->getMessage()}", 'warning');
        } catch (\Throwable $e) {
            $logger->log("Restore failed: {$e->getMessage()}", 'error');
            $log("Restore failed: {$e->getMessage()}", 'error');
            $client->fail($job['id'], $e->getMessage(), $logger->unsentLogs());
        }
    }
}
