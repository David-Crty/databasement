<?php

namespace App\Services\Backup\Databases;

use App\Contracts\BackupLogger;
use App\Exceptions\Backup\RestoreException;
use App\Exceptions\Backup\UnsupportedDatabaseTypeException;
use App\Services\Backup\DTO\DatabaseOperationResult;
use App\Services\Backup\Filesystems\Awss3Filesystem;
use League\Flysystem\Filesystem;

/**
 * S3-compatible object storage (MinIO, Backblaze B2, AWS S3, …) exposed to the
 * rest of the app as a "database server". The server points at one bucket and
 * its "databases" are the bucket's top-level prefixes — the units a bucket
 * backup configuration targets. Reading a bucket for backup and restoring a
 * bucket copy are bucket-copy operations, not SQL dumps, so {@see dump()},
 * {@see restore()} and {@see prepareForRestore()} throw here and the copy
 * engines (S3BucketBackupEngine / S3BucketRestoreEngine) run instead.
 */
class S3Database implements DatabaseInterface
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly Awss3Filesystem $awss3Filesystem = new Awss3Filesystem,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * Enumerate the bucket's top-level "databases" under any configured
     * prefix: the set of distinct first path segments below the root. Loose
     * objects at the root are reported as '' so folder-selection UIs can also
     * target them.
     *
     * @return array<string>
     */
    public function listDatabases(): array
    {
        $filesystem = $this->getFilesystem();

        // Non-recursive listing (the AWS adapter's "/" delimiter mode), so a
        // large bucket is never walked object-by-object just to learn its
        // top-level folders: only each immediate child is returned.
        try {
            $entries = $filesystem->listContents('', false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to list objects in bucket: '.$e->getMessage(), previous: $e);
        }

        $segments = [];
        foreach ($entries as $entry) {
            // A directory at the top level is itself the folder to back up. A
            // top-level file is a loose object at the bucket root, which maps to
            // the '' scope so the UI can also protect it.
            $segments[] = $entry->isDir() ? $this->firstPathSegment($entry->path()) : '';
        }

        // Deduplicate without dropping '' (the root segment, which a root-level
        // loose object contributes) — array_filter() would erase it.
        $databases = array_values(array_unique($segments));

        sort($databases);

        return $databases;
    }

    /**
     * The concrete Flysystem connection over the S3 bucket. Consumed directly
     * by the bucket backup/restore engines.
     */
    public function getFilesystem(): Filesystem
    {
        return $this->awss3Filesystem->get($this->config);
    }

    public function dump(string $outputPath): DatabaseOperationResult
    {
        throw new UnsupportedDatabaseTypeException('s3');
    }

    public function restore(string $inputPath): DatabaseOperationResult
    {
        throw new RestoreException('S3/object storage backups are restored as bucket copies, not database restores.');
    }

    public function prepareForRestore(string $schemaName, BackupLogger $logger, bool $forceDatabase = false): void
    {
        throw new RestoreException('S3/object storage backups are restored as bucket copies, not database restores.');
    }

    /**
     * Test the S3 connection by resolving the client and reading the bucket
     * root — this turns an invalid endpoint, missing credentials or a
     * nonexistent bucket into a failed ListObjectsV2.
     *
     * @return array{success: bool, message: string, details: array<string, mixed>}
     */
    public function testConnection(): array
    {
        $startTime = microtime(true);
        $host = (string) ($this->config['host'] ?? 'unknown');
        $bucket = (string) ($this->config['bucket'] ?? 'unknown');

        try {
            $this->getFilesystem()->listContents('', false);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => [
                    'object_storage' => 's3',
                    'endpoint' => $host,
                    'bucket' => $bucket,
                    'latency_ms' => (int) round((microtime(true) - $startTime) * 1000),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
                'details' => ['endpoint' => $host, 'bucket' => $bucket],
            ];
        }
    }

    /**
     * Return the first path segment of a (top-level) entry path. Directory
     * paths surface as their own folder name; a path that is empty after
     * trimming maps to '' only via listDatabases()'s file handling.
     */
    private function firstPathSegment(string $path): string
    {
        $path = trim($path, '/');
        $firstSlash = strpos($path, '/');

        return $firstSlash === false ? $path : substr($path, 0, $firstSlash);
    }
}
