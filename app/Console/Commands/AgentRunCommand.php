<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentApiClient;
use App\Services\Agent\AgentAuthenticationException;
use App\Services\Agent\Runners\AgentJobRunner;
use App\Services\Agent\Runners\BackupJobRunner;
use App\Services\Agent\Runners\DiscoveryJobRunner;
use App\Services\Agent\Runners\RestoreJobRunner;
use Illuminate\Console\Command;

class AgentRunCommand extends Command
{
    protected $signature = 'agent:run {--once : Run a single poll iteration and exit}';

    protected $description = 'Run the remote agent (polls the Databasement server for backup and restore jobs)';

    /**
     * The job types this agent runs. The server only hands out jobs whose
     * type is listed here.
     *
     * @var list<class-string<AgentJobRunner>>
     */
    private const RUNNERS = [
        BackupJobRunner::class,
        DiscoveryJobRunner::class,
        RestoreJobRunner::class,
    ];

    private bool $shouldStop = false;

    private ?string $runningJobId = null;

    public function handle(): int
    {
        $url = config('agent.url');
        $token = config('agent.token');
        $pollInterval = max(1, (int) config('agent.poll_interval', 5));

        if (empty($url) || empty($token)) {
            $this->log('DATABASEMENT_URL and DATABASEMENT_AGENT_TOKEN must be configured.', 'error');

            return self::FAILURE;
        }

        $client = new AgentApiClient($url, $token);
        $runners = $this->runners();

        $this->log('Databasement Agent starting...');
        $this->log("Server: {$url}");
        $this->log("Poll interval: {$pollInterval}s");

        $this->registerSignalHandlers($client);

        while (! $this->shouldStop) {
            try {
                $client->heartbeat();

                $job = $client->claimJob(array_keys($runners));

                if ($job !== null) {
                    $this->runJob($job, $runners, $client);
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

    /**
     * @return array<string, AgentJobRunner>
     */
    private function runners(): array
    {
        $runners = [];

        foreach (self::RUNNERS as $runnerClass) {
            $runner = app($runnerClass);
            $runners[$runner->type()->value] = $runner;
        }

        return $runners;
    }

    /**
     * @param  array{id: string, type?: string, payload: array<string, mixed>, merges_logs?: bool}  $job
     * @param  array<string, AgentJobRunner>  $runners
     */
    private function runJob(array $job, array $runners, AgentApiClient $client): void
    {
        // Servers up to 1.8 only send the type inside the payload, and
        // backup payloads never carried one.
        $type = $job['type'] ?? $job['payload']['type'] ?? 'backup';
        $runner = $runners[$type] ?? null;

        if ($runner === null) {
            $this->log("Job {$job['id']} has unsupported type '{$type}'.", 'error');
            $client->fail($job['id'], "This agent cannot run '{$type}' jobs. Update the agent.");

            return;
        }

        $this->runningJobId = $job['id'];

        try {
            $runner->run($job, $client, $this->log(...));
        } finally {
            $this->runningJobId = null;
        }
    }

    private function registerSignalHandlers(AgentApiClient $client): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop('SIGTERM', $client));
            pcntl_signal(SIGINT, fn () => $this->stop('SIGINT', $client));
        }
    }

    /**
     * Finish the running job, then exit. The job's log is told first, since
     * an orchestrator usually kills the agent before the job can finish
     * (Kubernetes eviction, scale-down or rollout), and the server would
     * otherwise only see the agent go silent.
     */
    private function stop(string $signal, AgentApiClient $client): void
    {
        if ($this->shouldStop) {
            return;
        }

        $this->shouldStop = true;

        if ($this->runningJobId === null) {
            return;
        }

        $message = "Agent received {$signal} and is shutting down. If it is stopped before the job finishes, the job will be retried.";
        $this->log($message, 'warning');

        try {
            $client->jobHeartbeat($this->runningJobId, [[
                'timestamp' => now()->toIso8601String(),
                'type' => 'log',
                'level' => 'warning',
                'message' => $message,
            ]]);
        } catch (\Throwable $e) {
            $this->log("Could not report the shutdown to the server: {$e->getMessage()}", 'warning');
        }
    }

    private function log(string $message, string $level = 'info'): void
    {
        $timestamp = now()->format('Y-m-d H:i:s');
        $prefix = strtoupper($level);
        $this->line("[{$timestamp}] {$prefix}: {$message}");
    }
}
