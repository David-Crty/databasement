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
     * far. An offset of 0 restarts the upload, and forgets any earlier commit
     * of the same file, which the new upload is about to replace.
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

        if ($offset === 0) {
            $this->discardCommitted($job, $volumeId, $path);
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

    /**
     * Record that the upload reached its volume with this SHA-256, so a retry
     * of the same completion can be told apart from a different upload.
     */
    public function markCommitted(AgentJob $job, string $volumeId, string $path, string $sha256): void
    {
        $markerPath = $this->basePath($job, $volumeId, $path).'.done';
        File::ensureDirectoryExists(dirname($markerPath));
        File::put($markerPath, $sha256);
    }

    /**
     * The SHA-256 the upload was committed with, or null when it was not.
     */
    public function committedSha256(AgentJob $job, string $volumeId, string $path): ?string
    {
        $markerPath = $this->basePath($job, $volumeId, $path).'.done';

        return is_file($markerPath) ? File::get($markerPath) : null;
    }

    public function discard(AgentJob $job, string $volumeId, string $path): void
    {
        File::delete($this->partPath($job, $volumeId, $path));
    }

    /**
     * Forget that the file was committed, once the volume may no longer hold
     * what was recorded.
     */
    public function discardCommitted(AgentJob $job, string $volumeId, string $path): void
    {
        File::delete($this->basePath($job, $volumeId, $path).'.done');
    }

    /**
     * Drop every unfinished upload of the job, once it has completed or failed.
     */
    public function forget(AgentJob $job): void
    {
        File::deleteDirectory($this->jobDirectory($job));
    }

    /**
     * Drop the staged files of every job that has ended or no longer exists,
     * and return how many jobs had some.
     *
     * A job drops its files as it ends, but a write admitted just before can
     * land after that, and the request's own recheck cannot see an ending
     * still inside an uncommitted transaction. This sweep is the last word.
     * Pending jobs keep theirs: a reclaimed upload restarts at offset 0.
     * Directories created in the last 10 minutes are left for a later sweep,
     * so a write still landing is never cut short.
     */
    public function pruneEnded(): int
    {
        $root = storage_path('app/agent-relay');

        if (! is_dir($root)) {
            return 0;
        }

        $cutoff = now()->subMinutes(10)->getTimestamp();
        $jobIds = array_map('basename', array_filter(
            File::directories($root),
            fn (string $directory) => File::lastModified($directory) < $cutoff,
        ));

        if ($jobIds === []) {
            return 0;
        }

        $unfinishedIds = AgentJob::query()
            ->whereKey($jobIds)
            ->whereIn('status', [AgentJob::STATUS_PENDING, AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])
            ->pluck('id')
            ->all();

        $endedIds = array_diff($jobIds, $unfinishedIds);

        foreach ($endedIds as $jobId) {
            File::deleteDirectory($root.'/'.$jobId);
        }

        return count($endedIds);
    }

    private function partPath(AgentJob $job, string $volumeId, string $path): string
    {
        return $this->basePath($job, $volumeId, $path).'.part';
    }

    private function basePath(AgentJob $job, string $volumeId, string $path): string
    {
        return $this->jobDirectory($job).'/'.hash('sha256', $volumeId.'|'.$path);
    }

    private function jobDirectory(AgentJob $job): string
    {
        return storage_path('app/agent-relay/'.$job->id);
    }
}
