<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\VolumeType;
use App\Http\Controllers\Controller;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Services\Backup\Filesystems\Awss3Filesystem;
use App\Services\Backup\Filesystems\FilesystemProvider;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SnapshotDownloadController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Request $request, Snapshot $snapshot): BinaryFileResponse|StreamedResponse|RedirectResponse
    {
        $this->authorize('download', $snapshot);

        // `file` picks which stored copy to download (multi-volume snapshots);
        // absent, the first available copy is used.
        $fileId = $request->query('file');
        $file = is_string($fileId) && $fileId !== ''
            ? $snapshot->files()->completed()->fileExists()->with('volume')->findOrFail($fileId)
            : $snapshot->primaryFile();

        abort_if($file === null, 404, __('Backup file not found.'));

        return match ($file->volume->getVolumeType()) {
            VolumeType::LOCAL => $this->downloadLocal($file),
            VolumeType::S3 => $this->downloadS3($file),
            default => $this->downloadStream($file),
        };
    }

    /**
     * Stream a local file directly — no memory buffering.
     */
    private function downloadLocal(SnapshotFile $file): BinaryFileResponse
    {
        $volumeRoot = $file->volume->config['path'] ?? $file->volume->config['root'] ?? '';
        $fullPath = rtrim($volumeRoot, '/').'/'.$file->storedFilename();

        abort_unless(
            is_file($fullPath) && is_readable($fullPath),
            404,
            __('Backup file not found or not readable by the web server. Ensure the volume path is mounted into the web container and readable by the application user.')
        );

        return response()->download($fullPath, basename($file->storedFilename()));
    }

    /**
     * Redirect to a presigned S3 URL — browser downloads directly from S3.
     */
    private function downloadS3(SnapshotFile $file): RedirectResponse
    {
        $s3Filesystem = app(Awss3Filesystem::class);
        $presignedUrl = $s3Filesystem->getPresignedUrl(
            $file->volume->getDecryptedConfig(),
            $file->storedFilename(),
            expiresInMinutes: 15
        );

        return redirect()->away($presignedUrl);
    }

    /**
     * Stream from remote filesystems (SFTP, FTP) via Flysystem.
     */
    private function downloadStream(SnapshotFile $file): StreamedResponse
    {
        $filesystem = app(FilesystemProvider::class)->getForVolume($file->volume);
        $filename = $file->storedFilename();

        abort_unless($filesystem->fileExists($filename), 404, __('Backup file not found.'));

        return response()->streamDownload(function () use ($filesystem, $filename) {
            $stream = $filesystem->readStream($filename);
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, basename($filename));
    }
}
