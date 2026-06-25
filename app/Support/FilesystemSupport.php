<?php

namespace App\Support;

use App\Facades\AppConfig;
use League\Flysystem\Filesystem;
use RuntimeException;

class FilesystemSupport
{
    /**
     * Create a unique working directory for a job.
     *
     * @param  string  $prefix  Prefix for the directory name (e.g., 'backup', 'restore')
     * @param  string  $id  Unique identifier for the job
     * @return string The path to the created directory
     *
     * @throws RuntimeException If directory creation fails
     */
    public static function createWorkingDirectory(string $prefix, string $id): string
    {
        $baseDirectory = rtrim(AppConfig::get('backup.working_directory'), '/');
        $workingDirectory = $baseDirectory.'/'.$prefix.'-'.$id;

        if (! is_dir($workingDirectory) && ! mkdir($workingDirectory, 0755, true)) {
            throw new RuntimeException("Failed to create working directory: {$workingDirectory}");
        }

        return $workingDirectory;
    }

    /**
     * Remove a directory and all contents recursively.
     */
    public static function cleanupDirectory(string $directory, bool $preserve = false): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        if (! $preserve) {
            rmdir($directory);
        }
    }

    /**
     * Remove now-empty parent directories left behind after deleting a file.
     *
     * Backup paths may contain date placeholders (e.g. "2026_06_15"), creating a folder
     * per backup. Once the last file in such a folder is deleted, the empty folder lingers
     * on the volume. Walk up the path, deleting each directory that has become empty, and
     * stop at the first non-empty directory or the volume root.
     *
     * @param  string  $path  Path of the deleted file, relative to the filesystem root.
     * @param  array<string, mixed>  $logContext  Extra context included in the warning if pruning fails.
     */
    public static function deleteEmptyParentDirectories(Filesystem $filesystem, string $path, array $logContext = []): void
    {
        try {
            $directory = dirname($path);

            while ($directory !== '' && $directory !== '.' && $directory !== '/' && $directory !== '\\') {
                if ($filesystem->listContents($directory, false)->toArray() !== []) {
                    break;
                }

                $filesystem->deleteDirectory($directory);
                $directory = dirname($directory);
            }
        } catch (\Exception $e) {
            // The file was already removed; a leftover empty directory is harmless.
            logger()->warning('Failed to delete empty parent directory', array_merge($logContext, [
                'path' => $path,
                'error' => $e->getMessage(),
            ]));
        }
    }
}
