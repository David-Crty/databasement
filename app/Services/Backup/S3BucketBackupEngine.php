<?php

namespace App\Services\Backup;

use App\Contracts\BackupLogger;
use App\Enums\RunKind;
use App\Enums\SnapshotFileStatus;
use App\Models\Snapshot;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\DTO\VolumeConfig;
use App\Services\Backup\DTO\VolumeTransferResult;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Support\FilesystemSupport;
use App\Support\Formatters;
use League\Flysystem\Filesystem;
use PharData;

/**
 * Core bucket-copy backup engine for S3/object-storage "database servers".
 *
 * A run snapshots ONE object scope (the server's database_name folder, or the
 * whole bucket) into a tar + compressed archive. Runs for a folder form a
 * chain: a periodic "full" archive carries every object in scope; the
 * incrementals between two fulls carry only the objects whose size/mtime
 * changed since the previous completed run of the same scope. A source object
 * deleted since the previous run is embedded as a tombstone marker so a
 * restore of that run's state drops it rather than resurrecting older data.
 *
 * The engine mirrors {@see BackupTask}: it builds the archive, uploads one
 * copy to every target Volume, and returns a structured outcome (run kind,
 * per-volume results, object rows, effective state) that the job layer
 * persists onto the Snapshot/SnapshotFile rows. Contains no Eloquent writes.
 */
class S3BucketBackupEngine
{
    /** Metadata key carrying the effective-state map persisted on runs. */
    public const string META_STATE_KEY = 's3.object_state';

    /** Marker appended to a tar member path to signal a source deletion. */
    public const string TOMBSTONE_SUFFIX = '.databasement-removed';

    /** Base name of the uncompressed tarball inside the working directory. */
    public const string PAYLOAD_BASENAME = 'payload';

    /** Default cadence: run a full every N runs of a folder. */
    public const int DEFAULT_FULL_EVERY_RUNS = 4;

    public function __construct(
        private readonly CompressorFactory $compressorFactory,
        private readonly FilesystemProvider $filesystemProvider,
    ) {}

    /**
     * @param  list<VolumeConfig>  $targets
     * @param  callable(int, int):void|null  $onChanged
     * @return array{
     *     run_kind: RunKind,
     *     full_snapshot_id: string|null,
     *     filename: string,
     *     file_size: int,
     *     checksum: string,
     *     volume_results: list<VolumeTransferResult>,
     *     object_files: array<int, array<string, mixed>>,
     *     object_state: array<string, array{size: int, mtime: int}>,
     *     changed_count: int,
     *     deleted_count: int,
     * }
     */
    public function run(
        Snapshot $snapshot,
        Filesystem $source,
        string $scope,
        array $targets,
        BackupLogger $logger,
        int $fullEvery = self::DEFAULT_FULL_EVERY_RUNS,
        ?callable $onChanged = null,
    ): array {
        $workingDir = FilesystemSupport::createWorkingDirectory('s3backup', $snapshot->id);
        // The folder ("database") this run snapshots; a chain lives strictly
        // inside one folder. An empty scope means the bucket root.
        $chainScope = trim($scope, '/');
        $runKind = $this->decideRunKind($snapshot, $fullEvery, $chainScope);

        try {
            $current = $this->listObjects($source, $scope);

            if ($runKind->isFull()) {
                // Full runs archive everything; a deleted object simply vanishes
                // (it is absent from this archive, which is self-contained).
                $changed = $current;
                $deleted = [];
            } else {
                [$changed, $deleted] = $this->diff($this->priorEffectiveState($snapshot, $chainScope), $current);
            }

            if ($onChanged !== null) {
                $onChanged(count($changed), count($deleted));
            }

            $logger->log(sprintf(
                'Bucket %s run for %s: %d changed, %d deleted',
                $runKind->value,
                $scope === '' ? '(root)' : $scope,
                count($changed),
                count($deleted),
            ), $changed === [] && $deleted === [] ? 'info' : 'success');

            [$archivePath, $checksum, $fileSize] = $this->buildArchive($workingDir, $source, $changed, $deleted, $snapshot);
            $filename = $this->archiveName($snapshot, $scope, $runKind, $archivePath);

            $results = [];
            foreach ($targets as $volume) {
                $results[] = $this->uploadVolume($volume, $archivePath, $filename, $fileSize, $logger);
            }

            return [
                'run_kind' => $runKind,
                'full_snapshot_id' => ! $runKind->isFull() ? $this->anchorFullId($snapshot, $chainScope) : null,
                'filename' => $filename,
                'file_size' => $fileSize,
                'checksum' => $checksum,
                'volume_results' => $results,
                'object_files' => $this->shapeObjectFiles($changed, $deleted, $runKind),
                'object_state' => $this->shapeState($current),
                'changed_count' => count($changed),
                'deleted_count' => count($deleted),
            ];
        } finally {
            FilesystemSupport::cleanupDirectory($workingDir);
        }
    }

    /**
     * List the objects inside a folder scope, keyed by their path **relative to
     * the scope** (never carrying the scope prefix). S3 keys are absolute to
     * the bucket root, but archives, diffs and the restore lineage all treat a
     * member as folder-relative (see {@see S3BucketRestoreEngine}), so the scope
     * prefix is stripped here exactly once so it is never re-applied later
     * (which previously produced doubled folders like `customers/customers/`).
     *
     * @return array<string, array{path: string, read: string, size: int, mtime: int}>
     */
    private function listObjects(Filesystem $fs, string $scope): array
    {
        $prefix = $scope === '' ? '' : rtrim($scope, '/').'/';
        $prefixLen = strlen($prefix);
        $objects = [];

        foreach ($fs->listContents($prefix, true) as $entry) {
            if (! $entry->isFile()) {
                continue;
            }
            $key = $entry->path();
            // Relative to the scope. Root-scope objects (scope '') have no prefix.
            $rel = $prefix === '' ? $key : substr($key, $prefixLen);

            if ($rel === '') {
                continue;
            }

            $size = $fs->fileSize($key);
            $objects[$rel] = [
                'path' => $rel,
                // The key as it exists on the mounted source filesystem: object
                // content is read by this raw key, but archived/compared by the
                // scope-relative `path` above.
                'read' => $key,
                'size' => (int) $size,
                'mtime' => $this->safeLastModified($fs, $key),
            ];
        }

        ksort($objects);

        return $objects;
    }

    private function safeLastModified(Filesystem $fs, string $path): int
    {
        try {
            return (int) $fs->lastModified($path);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<string, array{size: ?int, mtime: ?int}>  $baseline
     * @param  array<string, array{path: string, read: string, size: int, mtime: int}>  $current
     * @return array{0: array<string, array{path: string, read: string, size: int, mtime: int}>, 1: string[]}
     */
    private function diff(array $baseline, array $current): array
    {
        $changed = [];
        foreach ($current as $path => $obj) {
            $prior = $baseline[$path] ?? null;
            // safeLastModified() yields 0 when lastModified() is unavailable.
            // Two runs can both read 0 for a genuinely changed object, so never
            // treat mtime 0 as evidence things are unchanged — such objects are
            // always re-archived (harmless over-capture, never silent omission).
            $same = $prior !== null
                && (int) $prior['size'] === (int) $obj['size']
                && (int) $obj['mtime'] !== 0
                && (int) $prior['mtime'] === (int) $obj['mtime'];

            if (! $same) {
                $changed[$path] = $obj;
            }
        }

        $deleted = [];
        foreach (array_keys($baseline) as $path) {
            if (! isset($current[$path])) {
                $deleted[] = $path;
            }
        }

        return [$changed, $deleted];
    }

    /**
     * Latest completed run of the same folder whose metadata carries the
     * effective state the next incremental diffs against. Folder-scoped: one
     * backup config may back up many folders and their chains must never mix.
     *
     * @return array<string, array{size: ?int, mtime: ?int}>
     */
    private function priorEffectiveState(Snapshot $snapshot, string $scope): array
    {
        $prior = Snapshot::query()
            ->where('database_server_id', $snapshot->database_server_id)
            ->where('backup_id', $snapshot->backup_id)
            ->where('database_name', $scope)
            ->whereNotNull('run_kind')
            ->where('started_at', '<', $snapshot->started_at)
            ->latest('started_at')
            ->first();

        $state = $prior?->metadata[self::META_STATE_KEY] ?? null;

        return is_array($state) ? $state : [];
    }

    /**
     * The anchor full run of the same folder that an incremental backs onto.
     * Folder-scoped for the same reason as {@see priorEffectiveState()}.
     */
    private function anchorFullId(Snapshot $snapshot, string $scope): ?string
    {
        return Snapshot::query()
            ->where('database_server_id', $snapshot->database_server_id)
            ->where('backup_id', $snapshot->backup_id)
            ->where('database_name', $scope)
            ->where('run_kind', RunKind::FULL->value)
            ->where('started_at', '<=', $snapshot->started_at)
            ->orderByDesc('started_at')
            ->value('id');
    }

    private function decideRunKind(Snapshot $snapshot, int $fullEvery, string $scope): RunKind
    {
        $latestFull = Snapshot::query()
            ->where('database_server_id', $snapshot->database_server_id)
            ->where('backup_id', $snapshot->backup_id)
            ->where('database_name', $scope)
            ->where('run_kind', RunKind::FULL->value)
            ->where('started_at', '<=', $snapshot->started_at)
            ->orderByDesc('started_at')
            ->value('id');

        if ($latestFull === null) {
            return RunKind::FULL;
        }

        $increments = Snapshot::query()
            ->where('database_server_id', $snapshot->database_server_id)
            ->where('backup_id', $snapshot->backup_id)
            ->where('database_name', $scope)
            ->where('run_kind', RunKind::INCREMENTAL->value)
            ->where('full_snapshot_id', $latestFull)
            ->where('started_at', '<=', $snapshot->started_at)
            ->count();

        if ($increments >= max(1, $fullEvery) - 1) {
            return RunKind::FULL;
        }

        return RunKind::INCREMENTAL;
    }

    /**
     * @param  array<string, array{path: string, read: string, size: int, mtime: int}>  $objects
     * @param  string[]  $deleted
     * @return array{0: string, 1: string, 2: int}
     */
    private function buildArchive(string $workDir, Filesystem $fs, array $objects, array $deleted, Snapshot $snapshot): array
    {
        $tarPath = $workDir.'/'.self::PAYLOAD_BASENAME.'.tar';
        $this->writeTar($tarPath, $fs, $objects, $deleted);
        // Null type falls back to the global backup compression setting.
        $compressor = $this->compressorFactory->make($snapshot->compression_type);
        $archive = $compressor->compress($tarPath);

        $checksum = (string) hash_file('sha256', $archive);
        $size = filesize($archive);

        return [$archive, $checksum, $size === false ? 0 : (int) $size];
    }

    /**
     * @param  array<string, array{path: string, read: string, size: int, mtime: int}>  $objects
     * @param  string[]  $deleted
     */
    private function writeTar(string $tarPath, Filesystem $fs, array $objects, array $deleted): void
    {
        $stageDir = dirname($tarPath).'/objects';
        FilesystemSupport::cleanupDirectory($stageDir);
        mkdir($stageDir, 0755, true);

        $tar = new PharData($tarPath);
        try {
            foreach ($objects as $obj) {
                $this->addObject($tar, $stageDir, $fs, (string) $obj['read'], (string) $obj['path']);
            }
            foreach ($deleted as $path) {
                $tar->addFromString($path.self::TOMBSTONE_SUFFIX, '');
            }
        } finally {
            FilesystemSupport::cleanupDirectory($stageDir);
        }
    }

    private function addObject(PharData $tar, string $stageDir, Filesystem $fs, string $readKey, string $member): void
    {
        // PharData refuses absolute paths and path-traversal members; scope is
        // already a relative folder, and we also flatten the local name using a
        // content-derived hash so collision-free staging names stay short.
        // `$readKey` is the object's key on the mounted source filesystem (it may
        // include the folder scope), while `$member` is the scope-relative
        // archive path — so a later restore never re-prefixes the scope and
        // produces a doubled folder (`customers/customers/...`).
        $local = $stageDir.'/'.substr((string) hash('sha256', $member), 0, 20);

        $stream = $fs->readStream($readKey);
        $out = fopen($local, 'wb');
        if ($out === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new \RuntimeException('Failed to open local staging file for object: '.$readKey);
        }

        try {
            $copied = stream_copy_to_stream($stream, $out);
            if ($copied === false) {
                throw new \RuntimeException('Failed to copy object into the backup archive: '.$readKey);
            }
        } catch (\Throwable $e) {
            // Never add a partial object to the archive; drop the staging file.
            @unlink($local);
            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_resource($out)) {
                fclose($out);
            }
        }

        // Member path is the folder-relative object key.
        $tar->addFile($local, $member);
        unlink($local);
    }

    private function archiveName(Snapshot $snapshot, string $scope, RunKind $runKind, string $archivePath): string
    {
        $server = preg_replace('/[^a-zA-Z0-9-_]/', '-', ($snapshot->databaseServer->name ?? 's3')) ?? 's3';
        $scopePart = preg_replace('/[^a-zA-Z0-9-_]/', '-', $scope !== '' ? $scope : 'root') ?? 'root';
        // Derive the timestamp from the snapshot (stable across attempts), not
        // the execution clock. Every copy of one run must share the same archive
        // name because SnapshotFile::storedFilename() reads snapshot.filename — a
        // retry renaming the archive (now()) would make earlier copies on other
        // volumes unreachable.
        $ts = $snapshot->started_at->setTimezone(config('app.display_timezone'))->format('Y-m-d-His');
        $ext = pathinfo($archivePath, PATHINFO_EXTENSION) ?: 'gz';

        return sprintf('%s-%s-%s.s3.%s.%s', $server, $scopePart, $ts, $runKind->value, $ext);
    }

    /**
     * @param  array<string, array{path: string, size: int, mtime: int}>  $current
     * @return array<string, array{size: int, mtime: int}>
     */
    private function shapeState(array $current): array
    {
        $out = [];
        foreach ($current as $path => $obj) {
            $out[$path] = ['size' => $obj['size'], 'mtime' => $obj['mtime']];
        }

        return $out;
    }

    /**
     * @param  array<string, array{path: string, size: int, mtime: int}>  $changed
     * @param  string[]  $deleted
     * @return array<int, array<string, mixed>>
     */
    private function shapeObjectFiles(array $changed, array $deleted, RunKind $runKind): array
    {
        $rows = [];

        if ($runKind->isFull()) {
            foreach ($changed as $obj) {
                $rows[] = $this->row($obj, false);
            }

            return $rows;
        }

        foreach ($changed as $obj) {
            $rows[] = $this->row($obj, false);
        }
        foreach ($deleted as $path) {
            $rows[] = $this->row(['path' => $path, 'size' => 0, 'mtime' => 0], true);
        }

        return $rows;
    }

    /**
     * @param  array{path: string, size: int, mtime: int}  $obj
     * @return array<string, mixed>
     */
    private function row(array $obj, bool $tombstone): array
    {
        return [
            'path' => (string) $obj['path'],
            'size' => $tombstone ? null : (int) $obj['size'],
            'mtime' => $tombstone ? null : gmdate(DATE_ATOM, (int) $obj['mtime']),
            'checksum' => null,
            'tombstone' => $tombstone,
        ];
    }

    private function uploadVolume(VolumeConfig $volume, string $archivePath, string $filename, int $fileSize, BackupLogger $logger): VolumeTransferResult
    {
        try {
            $this->filesystemProvider->transferFromConfig($volume, $archivePath, $filename);
            $logger->log('Uploaded bucket run to volume: '.$volume->name.' ('.Formatters::humanFileSize($fileSize).')', 'success');

            return new VolumeTransferResult(
                volumeId: $volume->id,
                volumeName: $volume->name,
                status: SnapshotFileStatus::Completed,
            );
        } catch (\Throwable $e) {
            $logger->log('Upload to volume '.$volume->name.' failed: '.$e->getMessage(), 'error');

            return new VolumeTransferResult(
                volumeId: $volume->id,
                volumeName: $volume->name,
                status: SnapshotFileStatus::Failed,
                error: $e->getMessage(),
            );
        }
    }
}
