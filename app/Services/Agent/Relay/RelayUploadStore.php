<?php

namespace App\Services\Agent\Relay;

use App\Models\AgentJob;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Assembles the chunks of a file an agent uploads through the server, on the
 * server's disk, until the agent completes the upload and the file is sent
 * on to its volume.
 */
class RelayUploadStore
{
    /**
     * Append a chunk at the given offset and return the bytes received so
     * far. An offset of 0 restarts the upload.
     *
     * @param  resource  $chunk
     *
     * @throws RelayOffsetMismatchException When the offset is not where the upload stands
     */
    public function append(AgentJob $job, string $volumeId, string $path, int $offset, $chunk): int
    {
        $partPath = $this->partPath($job, $volumeId, $path);
        File::ensureDirectoryExists(dirname($partPath));

        clearstatcache(true, $partPath);
        $received = is_file($partPath) ? (int) filesize($partPath) : 0;

        if ($offset !== 0 && $offset !== $received) {
            throw new RelayOffsetMismatchException($received);
        }

        $part = fopen($partPath, $offset === 0 ? 'wb' : 'ab');
        if ($part === false) {
            throw new RuntimeException("Could not open the upload of {$path}.");
        }

        try {
            if (stream_copy_to_stream($chunk, $part) === false) {
                throw new RuntimeException("Could not store a chunk of {$path}.");
            }
        } finally {
            fclose($part);
        }

        clearstatcache(true, $partPath);

        return (int) filesize($partPath);
    }

    /**
     * The local file holding the upload received so far, or null when none
     * was started.
     */
    public function received(AgentJob $job, string $volumeId, string $path): ?string
    {
        $partPath = $this->partPath($job, $volumeId, $path);

        return is_file($partPath) ? $partPath : null;
    }

    public function discard(AgentJob $job, string $volumeId, string $path): void
    {
        File::delete($this->partPath($job, $volumeId, $path));
    }

    /**
     * Drop every unfinished upload of the job, once it has completed or failed.
     */
    public function forget(AgentJob $job): void
    {
        File::deleteDirectory($this->jobDirectory($job));
    }

    private function partPath(AgentJob $job, string $volumeId, string $path): string
    {
        return $this->jobDirectory($job).'/'.hash('sha256', $volumeId.'|'.$path).'.part';
    }

    private function jobDirectory(AgentJob $job): string
    {
        return storage_path('app/agent-relay/'.$job->id);
    }
}
