<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AgentJobType;
use App\Http\Controllers\Api\V1\Concerns\RejectsInactiveAgentJobs;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Volume;
use App\Services\Agent\Relay\RelayOffsetMismatchException;
use App\Services\Agent\Relay\RelayUploadStore;
use App\Services\Agent\Relay\RelayVolumes;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Support\FilesystemSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The file endpoints an agent set to transfer through the server reaches its
 * volumes with: each call runs on the real volume, within what the job allows.
 *
 * @tags Agent
 */
class AgentFileController extends Controller
{
    use RejectsInactiveAgentJobs;

    public function __construct(
        private RelayVolumes $relayVolumes,
        private RelayUploadStore $uploads,
        private FilesystemProvider $filesystemProvider,
    ) {}

    /**
     * Upload a file chunk.
     *
     * Appends the raw request body at `offset` to the file being uploaded to
     * the volume. An offset of 0 restarts the upload; any other offset must be
     * where the upload stands, or the response is a 409 carrying `received`.
     * A chunk larger than PHP's post_max_size is refused with a 413.
     */
    public function uploadChunk(Request $request, AgentJob $agentJob, string $volumeId): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:1000',
            'offset' => 'required|integer|min:0',
        ]);

        if ($rejection = $this->reject($request, $agentJob, $volumeId, RelayVolumes::WRITE, $validated['path'])) {
            return $rejection;
        }

        try {
            $received = $this->uploads->append($agentJob, $volumeId, $validated['path'], (int) $validated['offset'], $request->getContent(true));
        } catch (RelayOffsetMismatchException $e) {
            return response()->json(['message' => $e->getMessage(), 'received' => $e->received], Response::HTTP_CONFLICT);
        }

        if ($ended = $this->forgetIfEnded($agentJob)) {
            return $ended;
        }

        $agentJob->extendLease();

        return response()->json(['received' => $received]);
    }

    /**
     * Complete an upload.
     *
     * Checks that all `size` bytes arrived with the given SHA-256, then writes
     * the file to the volume. The write keeps running if the agent or a proxy
     * drops the request: a completion sent meanwhile answers 202 until it
     * ends, and one sent after succeeds when it carries the SHA-256 the
     * upload was committed with. A file the job did not write is never
     * overwritten.
     */
    public function completeUpload(Request $request, AgentJob $agentJob, string $volumeId): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:1000',
            'size' => 'required|integer|min:0',
            'sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);
        $path = $validated['path'];

        if ($rejection = $this->reject($request, $agentJob, $volumeId, RelayVolumes::WRITE, $path)) {
            return $rejection;
        }

        $lock = $this->uploads->completionLock($agentJob, $volumeId, $path);

        if (! $lock->get()) {
            $agentJob->extendLease();

            return response()->json(['status' => 'in_progress'], Response::HTTP_ACCEPTED);
        }

        try {
            return $this->commitUpload($request, $agentJob, $volumeId, $path, (int) $validated['size'], $validated['sha256']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Write a fully received upload to its volume, under its completion lock.
     */
    private function commitUpload(Request $request, AgentJob $agentJob, string $volumeId, string $path, int $size, string $sha256): JsonResponse
    {
        $partPath = $this->uploads->received($agentJob, $volumeId, $path);

        if ($partPath === null) {
            if ($this->uploads->committedSha256($agentJob, $volumeId, $path) === $sha256) {
                $agentJob->extendLease();

                return response()->json(['status' => 'ok']);
            }

            return response()->json(['message' => 'No upload of this file was started.', 'received' => 0], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $received = (int) filesize($partPath);

        if ($received !== $size) {
            return response()->json([
                'message' => "Received {$received} of {$size} bytes.",
                'received' => $received,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The write outlives a dropped connection; a retry waits on the lock.
        ignore_user_abort(true);
        set_time_limit(0);

        if (! hash_equals($sha256, (string) hash_file('sha256', $partPath))) {
            $this->uploads->discard($agentJob, $volumeId, $path);

            return response()->json(['message' => 'The uploaded file does not match its SHA-256.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $volume = $this->volume($request, $volumeId);

        if (! $this->uploads->owns($agentJob, $volumeId, $path)
            && $this->filesystemProvider->getForVolume($volume)->fileExists($path)) {
            $this->uploads->discard($agentJob, $volumeId, $path);

            return response()->json(['message' => 'A file this job did not write already exists at this path.'], Response::HTTP_CONFLICT);
        }

        // Owned before the write, so a write that fails halfway can be redone.
        $this->uploads->markOwned($agentJob, $volumeId, $path);

        try {
            $this->filesystemProvider->transfer($volume, $partPath, $path);
        } catch (Throwable $e) {
            // A failed write may have left the volume's file truncated, so an
            // earlier commit of it no longer holds.
            $this->uploads->discard($agentJob, $volumeId, $path);
            $this->uploads->discardCommitted($agentJob, $volumeId, $path);

            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Recorded before the staged part goes, so a retry always finds one.
        $this->uploads->markCommitted($agentJob, $volumeId, $path, $sha256);
        $this->uploads->discard($agentJob, $volumeId, $path);

        if ($ended = $this->forgetIfEnded($agentJob)) {
            return $ended;
        }

        $agentJob->extendLease();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Download a file.
     *
     * Streams the file from the volume.
     */
    public function download(Request $request, AgentJob $agentJob, string $volumeId): JsonResponse|StreamedResponse
    {
        $path = (string) $request->validate(['path' => 'required|string|max:1000'])['path'];

        if ($rejection = $this->reject($request, $agentJob, $volumeId, RelayVolumes::READ, $path)) {
            return $rejection;
        }

        $filesystem = $this->filesystemProvider->getForVolume($this->volume($request, $volumeId));

        if (! $filesystem->fileExists($path)) {
            return response()->json(['message' => 'File not found.'], Response::HTTP_NOT_FOUND);
        }

        $stream = $filesystem->readStream($path);
        $agentJob->extendLease();

        return response()->stream(function () use ($stream): void {
            set_time_limit(0);
            fpassthru($stream);
            fclose($stream);
        }, 200, ['Content-Type' => 'application/octet-stream']);
    }

    /**
     * Check whether a file exists.
     */
    public function exists(Request $request, AgentJob $agentJob, string $volumeId): JsonResponse
    {
        $path = (string) $request->validate(['path' => 'required|string|max:1000'])['path'];

        if ($rejection = $this->reject($request, $agentJob, $volumeId, RelayVolumes::EXISTS, $path)) {
            return $rejection;
        }

        $filesystem = $this->filesystemProvider->getForVolume($this->volume($request, $volumeId));

        return response()->json(['exists' => $filesystem->fileExists($path)]);
    }

    /**
     * Delete a file.
     *
     * Deletes the file and the directories it leaves empty. A file already
     * absent is not an error. A backup only deletes the files it wrote; a
     * cleanup deletes the copy the server chose for it.
     */
    public function delete(Request $request, AgentJob $agentJob, string $volumeId): JsonResponse
    {
        $path = (string) $request->validate(['path' => 'required|string|max:1000'])['path'];

        if ($rejection = $this->reject($request, $agentJob, $volumeId, RelayVolumes::DELETE, $path)) {
            return $rejection;
        }

        if ($agentJob->type === AgentJobType::Backup && ! $this->uploads->owns($agentJob, $volumeId, $path)) {
            return response()->json(['message' => 'This job can only delete files it wrote.'], Response::HTTP_FORBIDDEN);
        }

        $filesystem = $this->filesystemProvider->getForVolume($this->volume($request, $volumeId));

        return response()->json(['deleted' => FilesystemSupport::deleteFile($filesystem, $path)]);
    }

    /**
     * Drop the job's staged files when it ended while this request was
     * writing them: the job's own cleanup may have run before the write
     * landed, and nothing else would remove them afterwards.
     */
    private function forgetIfEnded(AgentJob $agentJob): ?JsonResponse
    {
        $stillActive = AgentJob::query()
            ->whereKey($agentJob->id)
            ->whereIn('status', [AgentJob::STATUS_CLAIMED, AgentJob::STATUS_RUNNING])
            ->exists();

        if ($stillActive) {
            return null;
        }

        $this->uploads->forget($agentJob);

        return response()->json(['message' => 'The job ended during the transfer.'], Response::HTTP_CONFLICT);
    }

    private function reject(Request $request, AgentJob $agentJob, string $volumeId, string $operation, string $path): ?JsonResponse
    {
        if ($rejection = $this->rejectUnlessActive($request, $agentJob, 'transfer files for')) {
            return $rejection;
        }

        /** @var Agent $agent */
        $agent = $request->user();
        $volume = Volume::withoutGlobalScopes()
            ->where('organization_id', $agent->organization_id)
            ->find($volumeId);

        if ($volume === null
            || ! RelayVolumes::relays($agent, $volume->type)
            || ! $this->relayVolumes->allows($agentJob, $volumeId, $operation, $path)) {
            return response()->json(['message' => 'This job does not allow this file operation.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function volume(Request $request, string $volumeId): Volume
    {
        /** @var Agent $agent */
        $agent = $request->user();

        return Volume::withoutGlobalScopes()
            ->where('organization_id', $agent->organization_id)
            ->findOrFail($volumeId);
    }
}
