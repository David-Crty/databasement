<?php

namespace App\Services\Agent;

use App\Exceptions\Backup\JobRevokedException;
use App\Services\Agent\Relay\RelayVolumes;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AgentApiClient
{
    public const string VERSION_HEADER = 'X-Databasement-Version';

    public const string COMMIT_HEADER = 'X-Databasement-Commit';

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
     * @return array{id: string, type?: string, snapshot_id: string|null, payload: array<string, mixed>, merges_logs?: bool}|null
     */
    public function claimJob(array $jobTypes): ?array
    {
        $response = $this->post('/agent/jobs/claim', [
            'job_types' => $jobTypes,
            'capabilities' => [RelayVolumes::CAPABILITY],
        ]);

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

        /** @var array{id: string, type?: string, snapshot_id: string|null, payload: array<string, mixed>, merges_logs?: bool} $job */
        return $job;
    }

    /**
     * Extend the job's lease and send the log entries recorded since the last
     * report.
     *
     * @param  array<int, array<string, mixed>>  $logs
     *
     * @throws JobRevokedException When the server no longer accepts reports for this job
     */
    public function jobHeartbeat(string $jobId, array $logs = []): void
    {
        $response = $this->post("/agent/jobs/{$jobId}/heartbeat", ['logs' => $logs]);

        // 401 once the token is revoked, 403 once the job is reassigned,
        // 404 once it is deleted, 409 once it has failed or completed.
        if (in_array($response->status(), [401, 403, 404, 409], true)) {
            throw new JobRevokedException($response->json('message') ?? "Job {$jobId} was rejected by the server.");
        }

        $response->throw();
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
     * Send one chunk of a file being uploaded through the server, starting at
     * byte `offset`. A retried chunk the server already stored counts as sent.
     */
    public function uploadChunk(string $jobId, string $volumeId, string $path, int $offset, string $chunk): void
    {
        $response = $this->fileRequest(timeout: 300)
            ->withBody($chunk, 'application/octet-stream')
            ->put($this->fileUrl($jobId, $volumeId, 'upload', ['path' => $path, 'offset' => $offset]));

        if ($response->status() === 409 && $response->json('received') !== null) {
            if ($response->json('received') === $offset + strlen($chunk)) {
                return;
            }

            throw new RuntimeException("The upload of {$path} is out of sync with the server: {$response->json('message')}");
        }

        $this->throwUnlessSuccessful($response);
    }

    /**
     * Finish an upload: the server checks it received `size` bytes and writes
     * the file to the volume.
     */
    public function completeUpload(string $jobId, string $volumeId, string $path, int $size): void
    {
        $response = $this->fileRequest(timeout: 3600)
            ->post($this->fileUrl($jobId, $volumeId, 'upload/complete'), ['path' => $path, 'size' => $size]);

        $this->throwUnlessSuccessful($response);
    }

    /**
     * Download a file of a volume through the server into a local file.
     */
    public function downloadFile(string $jobId, string $volumeId, string $path, string $destination): void
    {
        $response = $this->fileRequest(timeout: 3600)
            ->sink($destination)
            ->get($this->fileUrl($jobId, $volumeId, 'file', ['path' => $path]));

        $this->throwUnlessSuccessful($response);
    }

    public function fileExists(string $jobId, string $volumeId, string $path): bool
    {
        $response = $this->fileRequest(timeout: 60)
            ->get($this->fileUrl($jobId, $volumeId, 'file/exists', ['path' => $path]));

        $this->throwUnlessSuccessful($response);

        return (bool) $response->json('exists');
    }

    /**
     * Delete a file of a volume through the server, along with the
     * directories it leaves empty.
     */
    public function deleteFile(string $jobId, string $volumeId, string $path): void
    {
        $response = $this->fileRequest(timeout: 60)
            ->delete($this->fileUrl($jobId, $volumeId, 'file', ['path' => $path]));

        $this->throwUnlessSuccessful($response);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function post(string $path, array $data = [], int $timeout = 10, int $retries = 0): Response
    {
        return $this->request($timeout)
            ->when($retries > 0, fn (PendingRequest $request) => $request->retry($retries, 1000))
            ->post($this->baseUrl().$path, $data);
    }

    private function request(int $timeout): PendingRequest
    {
        return Http::withToken($this->token)
            ->withHeaders(self::versionHeaders())
            ->accept('application/json')
            ->timeout($timeout);
    }

    /**
     * A request to the job's file endpoints, retried when the connection
     * drops or the server errors, since a transfer can run for hours.
     */
    private function fileRequest(int $timeout): PendingRequest
    {
        return $this->request($timeout)->retry(
            3,
            2000,
            fn (Throwable $e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()),
            throw: false,
        );
    }

    private function baseUrl(): string
    {
        return rtrim($this->url, '/').'/api/v1';
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function fileUrl(string $jobId, string $volumeId, string $endpoint, array $query = []): string
    {
        $url = $this->baseUrl()."/agent/jobs/{$jobId}/volumes/{$volumeId}/{$endpoint}";

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    /**
     * @throws JobRevokedException When the server no longer accepts reports for this job
     */
    private function throwUnlessSuccessful(Response $response): void
    {
        // 401 once the token is revoked, 409 once the job has failed or completed.
        if (in_array($response->status(), [401, 409], true)) {
            throw new JobRevokedException($response->json('message') ?? 'The job was rejected by the server.');
        }

        if (! $response->successful()) {
            throw new RuntimeException($response->json('message') ?? "The server answered with HTTP {$response->status()}.");
        }
    }

    /**
     * Always sent, so the server can tell an untagged build ('dev') from an
     * agent too old to send the header at all.
     *
     * @return array<string, string>
     */
    private static function versionHeaders(): array
    {
        $headers = [self::VERSION_HEADER => config('app.version') ?: 'dev'];

        if ($commit = config('app.commit_hash')) {
            $headers[self::COMMIT_HEADER] = $commit;
        }

        return $headers;
    }
}
