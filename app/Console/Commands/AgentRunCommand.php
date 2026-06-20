<?php

namespace App\Console\Commands;

use App\Models\DatabaseServer;
use App\Services\Agent\AgentApiClient;
use App\Services\Agent\AgentAuthenticationException;
use App\Services\Backup\BackupTask;
use App\Services\Backup\Databases\DatabaseProvider;
use App\Services\Backup\DTO\BackupConfig;
use App\Services\Backup\InMemoryBackupLogger;
use App\Support\FilesystemSupport;
use Illuminate\Console\Command;

class AgentRunCommand extends Command
{
    protected $signature = 'agent:run {--once : Run a single poll iteration and exit}';

    protected $description = 'Run the remote backup agent (polls for jobs from the Databasement server)';

    private bool $shouldStop = false;

    public function handle(BackupTask $backupTask): int
    {
        $url = config('agent.url');
        $token = config('agent.token');
        $pollInterval = max(1, (int) config('agent.poll_interval', 5));

        if (empty($url) || empty($token)) {
            $this->log('DATABASEMENT_URL and DATABASEMENT_AGENT_TOKEN must be configured.', 'error');

            return self::FAILURE;
        }

        $client = new AgentApiClient($url, $token);

        $this->log('Databasement Agent starting...');
        $this->log("Server: {$url}");
        $this->log("Poll interval: {$pollInterval}s");

        $this->registerSignalHandlers();

        while (! $this->shouldStop) {
            try {
                $client->heartbeat();

                $job = $client->claimJob();

                if ($job !== null) {
                    $jobType = $job['payload']['type'] ?? 'backup';

                    if ($jobType === 'discover') {
                        $this->executeDiscoveryJob($job, $client);
                    } else {
                        $this->executeBackupJob($job, $client, $backupTask);
                    }
                } elseif (! $this->option('once')) {
                    sleep($pollInterval);
                }
            } catch (AgentAuthenticationException $e) {
                $this->log($e->getMessage(), 'error');

                return self::FAILURE;
            } catch (\Throwable $e) {
                $this->log($e->getMessage(), 'error');
                if (! $this->option('once')) {
                    sleep($pollInterval);
                }
            }

            if ($this->option('once')) {
                break;
            }
        }

        $this->log('Agent stopped gracefully.');

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
     * @param  array{id: string, snapshot_id: string|null, payload: array<string, mixed>}  $job
     */
    private function executeBackupJob(array $job, AgentApiClient $client, BackupTask $backupTask): void
    {
        $logger = new InMemoryBackupLogger;

        try {
            $payload = $job['payload'];
            $databaseName = $payload['database']['database_name'] ?? '';

            $this->log("Processing job {$job['id']}: {$payload['server_name']} / {$databaseName}");

            $logger->log("Starting backup for database: {$databaseName}", 'info');

            $workingDirectory = FilesystemSupport::createWorkingDirectory('backup', $job['id']);
            $config = BackupConfig::fromPayload($payload, $workingDirectory); // @phpstan-ignore argument.type

            $result = $backupTask->execute(
                $config,
                $logger,
                onProgress: fn () => $client->jobHeartbeat($job['id'], $logger->flush()),
            );

            $client->ack($job['id'], $result->filename, $result->fileSize, $result->checksum, $logger->flush());
            $this->log("Job completed: {$result->filename}");
        } catch (\Throwable $e) {
            $logger->log("Backup failed: {$e->getMessage()}", 'error');
            $this->log("Job failed: {$e->getMessage()}", 'error');
            $client->fail($job['id'], $e->getMessage(), $logger->flush());
        }
    }

    /**
     * @param  array{id: string, snapshot_id: string|null, payload: array<string, mixed>}  $job
     */
    private function executeDiscoveryJob(array $job, AgentApiClient $client): void
    {
        try {
            $payload = $job['payload'];
            $serverName = $payload['server_name'] ?? 'unknown';

            $this->log("Processing discovery job {$job['id']}: {$serverName}");

            $tempServer = DatabaseServer::forConnectionTest([
                'database_type' => $payload['database']['type'] ?? 'mysql',
                'host' => $payload['database']['host'] ?? '',
                'port' => $payload['database']['port'] ?? 3306,
                'username' => $payload['database']['username'] ?? '',
                'password' => $payload['database']['password'] ?? '',
                'extra_config' => $payload['database']['extra_config'] ?? null,
            ]);

            $databases = app(DatabaseProvider::class)->listDatabasesForServer($tempServer);

            if (($payload['selection_mode'] ?? '') === 'pattern' && ! empty($payload['pattern'])) {
                $databases = DatabaseServer::filterDatabasesByPattern($databases, $payload['pattern']);
            }

            $client->reportDiscoveredDatabases($job['id'], $databases);
            $this->log('Discovery completed: '.count($databases).' database(s) found');
        } catch (\Throwable $e) {
            $this->log("Discovery failed: {$e->getMessage()}", 'error');
            $client->fail($job['id'], $e->getMessage());
        }
    }

    private function log(string $message, string $level = 'info'): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $prefix = strtoupper($level);
        $this->line("[{$timestamp}] {$prefix}: {$message}");
    }
}
