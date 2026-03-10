<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\VolumeType;
use App\Http\Controllers\Controller;
use App\Models\Snapshot;
use App\Services\Backup\Filesystems\Awss3Filesystem;
use App\Services\Backup\Filesystems\FilesystemProvider;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SnapshotDownloadController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Snapshot $snapshot): BinaryFileResponse|StreamedResponse|RedirectResponse
    {
        $this->authorize('download', $snapshot);

        $snapshot->loadMissing('volume');

        return match ($snapshot->volume->getVolumeType()) {
            VolumeType::LOCAL => $this->downloadLocal($snapshot),
            VolumeType::S3 => $this->downloadS3($snapshot),
            default => $this->downloadStream($snapshot),
        };
    }

    /**
     * Stream a local file directly — no memory buffering.
     */
    private function downloadLocal(Snapshot $snapshot): BinaryFileResponse
    {
        $volumeRoot = $snapshot->volume->config['path'] ?? $snapshot->volume->config['root'] ?? '';
        $fullPath = rtrim($volumeRoot, '/').'/'.$snapshot->filename;

        abort_unless(file_exists($fullPath), 404, __('Backup file not found.'));

        return response()->download($fullPath, basename($snapshot->filename));
    }

    /**
     * Redirect to a presigned S3 URL — browser downloads directly from S3.
     */
    private function downloadS3(Snapshot $snapshot): RedirectResponse
    {
        $s3Filesystem = app(Awss3Filesystem::class);
        $presignedUrl = $s3Filesystem->getPresignedUrl(
            $snapshot->volume->getDecryptedConfig(),
            $snapshot->filename,
            expiresInMinutes: 15
        );

        return redirect()->away($presignedUrl);
    }

    /**
     * Stream from remote filesystems (SFTP, FTP) via Flysystem.
     */
    private function downloadStream(Snapshot $snapshot): StreamedResponse
    {
        $filesystem = app(FilesystemProvider::class)->getForVolume($snapshot->volume);

        abort_unless($filesystem->fileExists($snapshot->filename), 404, __('Backup file not found.'));

        return response()->streamDownload(function () use ($filesystem, $snapshot) {
            $stream = $filesystem->readStream($snapshot->filename);
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, basename($snapshot->filename));
    }
}
