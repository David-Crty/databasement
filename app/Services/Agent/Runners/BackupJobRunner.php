<?php

namespace App\Services\Agent\Runners;

use App\Enums\AgentJobType;
use App\Exceptions\Backup\VolumeTransferException;
use App\Services\Agent\AgentApiClient;
use App\Services\Backup\BackupTask;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\VolumeTransferResult;
use App\Services\Backup\InMemoryBackupLogger;
use App\Support\FilesystemSupport;
use Closure;

class BackupJobRunner implements AgentJobRunner
{
    public function __construct(
        private BackupTask $backupTask,
    ) {}

    public function type(): AgentJobType
    {
        return AgentJobType::Backup;
    }

    public function run(array $job, AgentApiClient $client, Closure $log): void
    {
        $logger = new InMemoryBackupLogger;

        try {
            $payload = $job['payload'];
            $databaseName = $payload['database']['database_name'] ?? '';

            $log("Processing job {$job['id']}: {$payload['server_name']} / {$databaseName}");

            $logger->log("Starting backup for database: {$databaseName}", 'info');

            $workingDirectory = FilesystemSupport::createWorkingDirectory('backup', $job['id']);
            $config = BackupConfig::fromPayload($payload, $workingDirectory); // @phpstan-ignore argument.type

            $result = $this->backupTask->execute(
                $config,
                $logger,
                onProgress: fn () => $client->jobHeartbeat($job['id'], $logger->flush()),
            );

            $client->ack(
                $job['id'],
                $result->filename,
                $result->fileSize,
                $result->checksum,
                $this->volumeResultPayloads($result->volumeResults),
                $logger->flush(),
            );
            $log("Job completed: {$result->filename}");
        } catch (VolumeTransferException $e) {
            // Some uploads may have succeeded — report the per-volume
            // outcomes so the app records the good copies before failing.
            $logger->log("Backup failed: {$e->getMessage()}", 'error');
            $log("Job failed: {$e->getMessage()}", 'error');
            $client->fail(
                $job['id'],
                $e->getMessage(),
                $logger->flush(),
                $this->volumeResultPayloads($e->result->volumeResults),
                $e->result->filename,
                $e->result->fileSize,
            );
        } catch (\Throwable $e) {
            $logger->log("Backup failed: {$e->getMessage()}", 'error');
            $log("Job failed: {$e->getMessage()}", 'error');
            $client->fail($job['id'], $e->getMessage(), $logger->flush());
        }
    }

    /**
     * @param  list<VolumeTransferResult>  $volumeResults
     * @return array<int, array<string, mixed>>
     */
    private function volumeResultPayloads(array $volumeResults): array
    {
        return array_map(fn (VolumeTransferResult $volumeResult) => $volumeResult->toPayload(), $volumeResults);
    }
}
