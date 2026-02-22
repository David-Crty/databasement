<?php

namespace App\Console\Commands;

use App\Enums\CompressionType;
use App\Services\Agent\AgentApiClient;
use App\Services\Backup\BackupTask;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\DTO\DatabaseConnectionConfig;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\InMemoryBackupLogger;
use Illuminate\Console\Command;

class AgentRunCommand extends Command
{
    protected $signature = 'agent:run';

    protected $description = 'Run the remote backup agent (polls for jobs from the Databasement server)';

    private bool $shouldStop = false;

    public function handle(BackupTask $backupTask): int
    {
        $url = config('agent.url');
        $token = config('agent.token');
        $pollInterval = config('agent.poll_interval', 5);

        if (empty($url) || empty($token)) {
            $this->error('DATABASEMENT_URL and DATABASEMENT_AGENT_TOKEN must be configured.');

            return self::FAILURE;
        }

        $client = new AgentApiClient($url, $token);

        $this->info('Databasement Agent starting...');
        $this->info("Server: {$url}");
        $this->info("Poll interval: {$pollInterval}s");
        $this->newLine();

        $this->registerSignalHandlers();

        while (! $this->shouldStop) {
            try {
                $client->heartbeat();

                $job = $client->claimJob();

                if ($job !== null) {
                    $this->executeJob($job, $client, $backupTask);
                } else {
                    sleep($pollInterval);
                }
            } catch (\Throwable $e) {
                $this->error("Error: {$e->getMessage()}");
                sleep($pollInterval);
            }
        }

        $this->info('Agent stopped gracefully.');

        return self::SUCCESS;
    }

    private function registerSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }
    }

    /**
     * @param  array{id: string, snapshot_id: string, payload: array<string, mixed>}  $job
     */
    private function executeJob(array $job, AgentApiClient $client, BackupTask $backupTask): void
    {
        $payload = $job['payload'];
        $dbConfig = $payload['database'];

        $this->info("Processing job {$job['id']}: {$payload['server_name']} / {$dbConfig['database_name']}");

        $logger = new InMemoryBackupLogger;
        $workingDirectory = sys_get_temp_dir().'/agent-backup-'.$job['id'];
        mkdir($workingDirectory, 0755, true);

        try {
            $logger->log("Starting backup for database: {$dbConfig['database_name']}", 'info');

            $config = new BackupConfig(
                database: DatabaseConnectionConfig::fromPayload($dbConfig, $payload['server_name']),
                volume: VolumeConfig::fromPayload($payload['volume']),
                databaseName: $dbConfig['database_name'],
                workingDirectory: $workingDirectory,
                backupPath: $payload['backup_path'] ?? '',
                compressionType: CompressionType::from($payload['compression']['type']),
                compressionLevel: $payload['compression']['level'] ?? null,
            );

            $result = $backupTask->execute(
                $config,
                $logger,
                onProgress: fn () => $client->jobHeartbeat($job['id'], $logger->flush()),
            );

            $client->ack($job['id'], $result->filename, $result->fileSize, $result->checksum, $logger->flush());
            $this->info("  Job completed: {$result->filename}");
        } catch (\Throwable $e) {
            $logger->log("Backup failed: {$e->getMessage()}", 'error');
            $this->error("  Job failed: {$e->getMessage()}");
            $client->fail($job['id'], $e->getMessage(), $logger->flush());
        }
    }
}
