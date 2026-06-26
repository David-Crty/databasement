<?php

namespace App\Services\Agent;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AgentApiClient
{
    public function __construct(
        private string $url,
        private string $token,
        private int $uploadTimeout = 3600,
        private int $uploadRetries = 3,
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
     * @return array{id: string, snapshot_id: string, payload: array<string, mixed>}|null
     */
    public function claimJob(): ?array
    {
        $response = $this->post('/agent/jobs/claim');

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

        /** @var array{id: string, snapshot_id: string, payload: array<string, mixed>} $job */
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
     * @param  array<int, array<string, mixed>>  $logs
     */
    public function ack(string $jobId, string $filename, int $fileSize, string $checksum, array $logs = []): void
    {
        $baseUrl = rtrim($this->url, '/');

        Http::withToken($this->token)
            ->accept('application/json')
            ->timeout(30)
            ->retry(3, 1000)
            ->post("{$baseUrl}/api/v1/agent/jobs/{$jobId}/ack", [
                'filename' => $filename,
                'file_size' => $fileSize,
                'checksum' => $checksum,
                'logs' => $logs,
            ])->throw();
    }

    /**
     * Relay the backup archive to the main server, which writes it to the
     * target volume. Streams the file as a raw request body to avoid loading
     * it into memory. The agent still acks afterwards to finalize the job.
     *
     * The upload is idempotent (it overwrites the same filename), so transient
     * network/5xx failures are retried; 4xx responses are not — they won't
     * recover by retrying.
     */
    public function upload(string $jobId, string $archivePath, string $filename, string $checksum, int $fileSize): void
    {
        $baseUrl = rtrim($this->url, '/');
        $query = http_build_query([
            'filename' => $filename,
            'checksum' => $checksum,
            'file_size' => $fileSize,
        ]);

        for ($attempt = 1; ; $attempt++) {
            try {
                // Re-open the file each attempt so the body stream starts at 0.
                // The Guzzle stream owns the handle and closes it after sending.
                $body = Utils::streamFor(Utils::tryFopen($archivePath, 'r'));

                Http::withToken($this->token)
                    ->accept('application/json')
                    ->timeout($this->uploadTimeout)
                    ->withBody($body, 'application/octet-stream')
                    ->post("{$baseUrl}/api/v1/agent/jobs/{$jobId}/upload?{$query}")
                    ->throw();

                return;
            } catch (RequestException $e) {
                // Only transient server errors are worth retrying.
                if ($attempt >= $this->uploadRetries || ! $e->response->serverError()) {
                    throw $e;
                }
            } catch (ConnectionException $e) {
                if ($attempt >= $this->uploadRetries) {
                    throw $e;
                }
            }

            sleep($attempt); // linear backoff: 1s, 2s, ...
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $logs
     */
    public function fail(string $jobId, string $errorMessage, array $logs = []): void
    {
        $this->post("/agent/jobs/{$jobId}/fail", [
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
    private function post(string $path, array $data = [], int $timeout = 10): Response
    {
        $baseUrl = rtrim($this->url, '/');

        return Http::withToken($this->token)
            ->accept('application/json')
            ->timeout($timeout)
            ->post("{$baseUrl}/api/v1{$path}", $data);
    }
}
