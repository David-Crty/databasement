<?php

namespace App\Support;

use RuntimeException;

class Filesystem
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
        $baseDirectory = rtrim(config('backup.working_directory'), '/');
        $workingDirectory = $baseDirectory.'/'.$prefix.'-'.$id;

        if (! is_dir($workingDirectory) && ! mkdir($workingDirectory, 0755, true)) {
            throw new RuntimeException("Failed to create working directory: {$workingDirectory}");
        }

        return $workingDirectory;
    }

    /**
     * Remove a directory and all contents recursively.
     */
    public static function cleanupDirectory(string $directory): void
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

        rmdir($directory);
    }
}
