<?php

namespace App\Services\Agent;

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
        $this->post('/agent/heartbeat')->throw();
    }

    /**
     * @return array{id: string, snapshot_id: string, payload: array<string, mixed>}|null
     */
    public function claimJob(): ?array
    {
        $response = $this->post('/agent/jobs/claim');

        if (! $response->successful()) {
            return null;
        }

        return $response->json('job');
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
        $this->post("/agent/jobs/{$jobId}/ack", [
            'filename' => $filename,
            'file_size' => $fileSize,
            'checksum' => $checksum,
            'logs' => $logs,
        ], 30)->throw();
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
     * @param  array<string, mixed>  $data
     */
    private function post(string $path, array $data = [], int $timeout = 10): Response
    {
        return Http::withToken($this->token)
            ->timeout($timeout)
            ->post("{$this->url}/api/v1{$path}", $data);
    }
}
