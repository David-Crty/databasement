<?php

namespace App\Services\Agent;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AgentApiClient
{
    public function __construct(
        private string $url,
        private string $token,
    ) {}

    public function heartbeat(): void
    {
        $response = $this->post('/agent/heartbeat');

        if ($response->status() === 401 || $response->status() === 403) {
            throw new AgentAuthenticationException('Authentication failed. Please check your DATABASEMENT_AGENT_TOKEN.');
        }

        $response->throw();
    }

    /**
     * @param  list<string>  $jobTypes  The job types this agent can run
     * @return array{id: string, type?: string, snapshot_id: string|null, payload: array<string, mixed>}|null
     */
    public function claimJob(array $jobTypes): ?array
    {
        $response = $this->post('/agent/jobs/claim', ['job_types' => $jobTypes]);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new AgentAuthenticationException('Authentication failed. Please check your DATABASEMENT_AGENT_TOKEN.');
        }

        if (! $response->successful()) {
            return null;
        }

        $job = $response->json('job');

        if (! is_array($job)) {
            return null;
        }

        /** @var array{id: string, type?: string, snapshot_id: string|null, payload: array<string, mixed>} $job */
        return $job;
    }

    /**
     * @param  array<int, array<string, mixed>>  $logs
     */
    public function jobHeartbeat(string $jobId, array $logs = []): void
    {
        $this->post("/agent/jobs/{$jobId}/heartbeat", empty($logs) ? [] : ['logs' => $logs])->throw();
    }

    /**
     * Report a completed job with its result, whose fields depend on the job type.
     *
     * @param  array<string, mixed>  $result
     * @param  array<int, array<string, mixed>>  $logs
     */
    public function ack(string $jobId, array $result = [], array $logs = []): void
    {
        $this->post("/agent/jobs/{$jobId}/ack", [...$result, 'logs' => $logs], timeout: 30, retries: 3)->throw();
    }

    /**
     * @param  array<int, array<string, mixed>>  $logs
     * @param  array<string, mixed>  $result  What the job got done before failing, such as the copies a backup uploaded
     */
    public function fail(string $jobId, string $errorMessage, array $logs = [], array $result = []): void
    {
        $this->post("/agent/jobs/{$jobId}/fail", [
            ...$result,
            'error_message' => Str::limit($errorMessage, 10000, ''),
            'logs' => $logs,
        ])->throw();
    }

    /**
     * @param  string[]  $databases
     */
    public function reportDiscoveredDatabases(string $jobId, array $databases): void
    {
        $this->post("/agent/jobs/{$jobId}/discovered-databases", [
            'databases' => $databases,
        ])->throw();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function post(string $path, array $data = [], int $timeout = 10, int $retries = 0): Response
    {
        $baseUrl = rtrim($this->url, '/');

        return Http::withToken($this->token)
            ->accept('application/json')
            ->timeout($timeout)
            ->when($retries > 0, fn (PendingRequest $request) => $request->retry($retries, 1000))
            ->post("{$baseUrl}/api/v1{$path}", $data);
    }
}
