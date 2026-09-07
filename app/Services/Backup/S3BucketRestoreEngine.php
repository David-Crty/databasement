<?php

namespace App\Services\Backup;

use App\Contracts\BackupLogger;
use App\Enums\RunKind;
use App\Models\Snapshot;
use App\Models\SnapshotFile;
use App\Services\Backup\Compressors\CompressorFactory;
use App\Services\Backup\Filesystems\FilesystemProvider;
use App\Support\FilesystemSupport;
use League\Flysystem\Filesystem;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Reconstruct a folder state as of a chosen bucket-copy run and write it into
 * an S3/object-storage destination scope.
 *
 * A run's archive is not self-contained: incremental archives carry only the
 * changed objects since the previous run, so to restore exactly the folder as
 * it looked at run R, one overlays every archive in R's lineage onto a merge
 * directory in order: first the anchor full archive, then each incremental up
 * to (and including) R. Tombstone members (.databasement-removed) make a
 * restore drop a path instead of resurrecting older data.
 */
class S3BucketRestoreEngine
{
    /**
     * Build the engine around the compressor factory (archive decompression)
     * and the filesystem provider (lineage downloads).
     */
    public function __construct(
        private readonly CompressorFactory $compressorFactory,
        private readonly FilesystemProvider $filesystemProvider,
    ) {}

    /**
     * Resolve and download R's lineage from the archive copies on a selected
     * volume, decompress+untar each into a staging directory, and upload the
     * merged result into the destination scope.
     *
     * @param  Snapshot  $run  The state to restore (full or incremental).
     * @param  Filesystem  $destMany  Destination S3 filesystem.
     * @param  string  $destScope  Destination folder ('' = bucket root).
     * @param  string|null  $volumeId  Restore source volume (null = first archive copy).
     */
    public function restore(
        Snapshot $run,
        Filesystem $destMany,
        string $destScope,
        BackupLogger $logger,
        ?string $volumeId = null,
    ): void {
        $chain = $this->resolveLineage($run, $volumeId);

        if ($chain === []) {
            throw new \RuntimeException('No restorable snapshots found for the chosen run.');
        }

        $workDir = FilesystemSupport::createWorkingDirectory('s3restore', $run->id);
        $mergeDir = $workDir.'/merge';

        try {
            mkdir($mergeDir, 0755, true);

            foreach ($chain as $entry) {
                $archive = $this->downloadLineageCopy($entry, $workDir, $logger);
                $tarPath = $this->decompressRun($entry['snapshot'], $archive, $workDir, $logger);
                $this->overlayRun($tarPath, $mergeDir);
            }

            $this->uploadMerge($mergeDir, $destMany, $destScope, $logger);
        } finally {
            FilesystemSupport::cleanupDirectory($workDir);
        }
    }

    /**
     * Ordered SnapshotFile+Snapshot entries to overlay: anchor full then the
     * incrementals up to the target run.
     *
     * Every member of the lineage must resolve to an existing archive copy or
     * the restore fails loudly instead of warping a partial chain into the
     * destination. This protects against a stray silent restore when retention
     * or an upload failure removed an anchor that a retained incremental still
     * references.
     *
     * Only completed runs can anchor or extend a lineage: a failed full is
     * persisted with run_kind/full_snapshot_id but has no usable archive, so
     * it must never be selected as the anchor for a later incremental.
     *
     * @return array<int, array{snapshot: Snapshot, file: SnapshotFile}>
     */
    private function resolveLineage(Snapshot $target, ?string $volumeId): array
    {
        $anchor = $this->resolveAnchor($target);

        $members = [];
        if ($anchor !== null) {
            $members[] = $anchor;
        }

        // If the chosen run itself is the anchor (and the anchor exists above
        // us to build from), only the anchor is needed.
        if ($anchor !== null && $target->id !== $anchor->id) {
            $incrementals = Snapshot::query()
                ->where('database_server_id', $target->database_server_id)
                ->where('backup_id', $target->backup_id)
                ->where('database_name', $target->database_name)
                ->where('run_kind', RunKind::INCREMENTAL->value)
                ->where('full_snapshot_id', $anchor->id)
                ->where('started_at', '>', $anchor->started_at)
                ->where('started_at', '<=', $target->started_at)
                ->completed()
                ->orderBy('started_at')
                ->get();

            $members = array_merge($members, $incrementals->all());

            // The chosen run must itself be part of the chain.
            $members = collect($members)
                ->push($target)
                ->unique('id')
                ->values()
                ->all();
            usort($members, fn (Snapshot $a, Snapshot $b) => $a->started_at <=> $b->started_at);
        }

        $resolved = [];
        foreach ($members as $snapshot) {
            $file = $this->archiveFileFor($snapshot, $volumeId);
            if ($file === null) {
                $kind = $snapshot->run_kind !== null ? $snapshot->run_kind->value : 'run';
                throw new \RuntimeException(sprintf(
                    'Cannot restore: archive for %s snapshot %s is unavailable on any volume. Its chain cannot be reconstructed without it.',
                    $kind,
                    $snapshot->id,
                ));
            }
            $resolved[] = ['snapshot' => $snapshot, 'file' => $file];
        }

        return $resolved;
    }

    /**
     * The anchor full a restore overlays first. An incremental records the
     * exact full it was built on ({@see Snapshot::$full_snapshot_id}, decided
     * against completed runs when the backup ran), so that run is preferred
     * over any newer completed full that may also predate the target. Falls
     * back to the latest completed full at or before the target (a full
     * targets itself).
     */
    private function resolveAnchor(Snapshot $target): ?Snapshot
    {
        if ($target->run_kind === RunKind::INCREMENTAL && $target->full_snapshot_id !== null) {
            $anchor = Snapshot::query()->whereKey($target->full_snapshot_id)->completed()->first();
            if ($anchor !== null) {
                return $anchor;
            }
        }

        return Snapshot::query()
            ->where('database_server_id', $target->database_server_id)
            ->where('backup_id', $target->backup_id)
            ->where('database_name', $target->database_name)
            ->where('run_kind', RunKind::FULL->value)
            ->where('started_at', '<=', $target->started_at)
            ->completed()
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * Resolve the archive copy of a lineage run: the completed copy on the
     * requested volume when given, otherwise the most recent completed copy on
     * any volume.
     */
    private function archiveFileFor(Snapshot $snapshot, ?string $volumeId): ?SnapshotFile
    {
        // Prefer the archive copy on the requested volume, but fall back to any
        // volume hosting a completed copy so a partial upload on one volume does
        // not make the whole chain unrestorable elsewhere.
        if ($volumeId !== null) {
            $file = $snapshot->files()->where('volume_id', $volumeId)->completed()->first();
            if ($file !== null) {
                return $file;
            }
        }

        return $snapshot->files()->completed()->latest('id')->first();
    }

    /**
     * @param  array{snapshot: Snapshot, file: SnapshotFile}  $entry
     */
    private function downloadLineageCopy(array $entry, string $workDir, BackupLogger $logger): string
    {
        /** @var SnapshotFile $file */
        $file = $entry['file'];
        $ext = $this->snapshotFileExtension($file);
        // Keep a recognised `.tar.<ext>` basename so decompression leaves a
        // `.tar` file that PharData can actually open on re-archive.
        $local = $workDir.'/lineage-'.$file->snapshot_id.'.tar.'.$ext;
        $logger->log('Downloading bucket run archive...', 'info');

        $this->filesystemProvider->download($file, $local);

        return $local;
    }

    /**
     * Compression extension of a stored archive filename ('tar' when it has
     * none) — the extension the compressor strips to reveal payload.tar.
     */
    private function snapshotFileExtension(SnapshotFile $file): string
    {
        $name = $file->storedFilename();
        $dot = strrpos($name, '.');
        $ext = $dot === false ? 'tar' : substr($name, $dot + 1);

        // Our archives are gzip/zstd/7z-wrapped tars; the compressed ext is what
        // the corresponding compressor strips to reveal payload.tar.
        return $ext === '' ? 'tar' : $ext;
    }

    /**
     * Decompress one lineage archive to a .tar path.
     */
    private function decompressRun(Snapshot $snapshot, string $archivedLocal, string $workDir, BackupLogger $logger): string
    {
        $compressor = $this->compressorFactory->make($snapshot->compression_type);
        $logger->log('Decompressing bucket run archive...', 'info');

        return $compressor->decompress($archivedLocal);
    }

    /**
     * Extract one lineage archive into a temp directory and overlay its
     * contents onto the merge directory (tombstones drop their base path).
     */
    private function overlayRun(string $tarPath, string $mergeDir): void
    {
        $tar = new PharData($tarPath);
        $tmp = $this->tempDirname($tarPath);

        try {
            // extractTo requires an empty target dir.
            if (! is_dir($tmp)) {
                mkdir($tmp, 0755, true);
            }
            $tar->extractTo($tmp);

            $this->walkAndOverlay($tmp, $mergeDir);
        } finally {
            FilesystemSupport::cleanupDirectory($tmp);
        }
    }

    /**
     * Stable temp extraction directory name derived from the tar path.
     */
    private function tempDirname(string $path): string
    {
        return dirname($path).'/extract-'.substr(hash('sha256', $path), 0, 10);
    }

    /**
     * Walk an extracted archive and copy every file into the merge directory,
     * overwriting same-path members from older runs; a tombstone member
     * removes its base path instead of copying it.
     */
    private function walkAndOverlay(string $extracted, string $mergeDir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extracted, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $rootLen = strlen(rtrim($extracted, '/')) + 1;
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            /** @var string $rel */
            $rel = ltrim((string) substr($file->getPathname(), $rootLen), '/');

            if (str_ends_with($rel, S3BucketBackupEngine::TOMBSTONE_SUFFIX)) {
                // A deletion: drop the base path from the merged output.
                $gone = substr($rel, 0, -strlen(S3BucketBackupEngine::TOMBSTONE_SUFFIX));
                @unlink($mergeDir.'/'.$gone);
                @rmdir(dirname($mergeDir.'/'.$gone));

                continue;
            }

            $destFile = $mergeDir.'/'.$rel;
            if (! is_dir(dirname($destFile))) {
                mkdir(dirname($destFile), 0755, true);
            }
            // Incrementals overwrite the earlier member of the same path.
            copy($file->getPathname(), $destFile);
        }
    }

    /**
     * Upload the merged state into the destination scope. The scope is wiped
     * first so the restore ends exactly as the selected run's state, never
     * accumulating objects the merge does not contain.
     */
    private function uploadMerge(string $mergeDir, Filesystem $dest, string $destScope, BackupLogger $logger): void
    {
        $prefix = $destScope === '' ? '' : rtrim($destScope, '/').'/';
        // A restore replaces the scope with the selected state, mirroring the
        // SQL drop-and-recreate flow: any object that already exists under the
        // destination scope but is absent from the restored merge (for example
        // an object deleted in the selected state, or a file that existed only
        // in a newer run when an older snapshot is restored) must be removed,
        // otherwise the scope accumulates stale data the merge never touches.
        $this->wipeDestinationScope($dest, $prefix);
        $uploaded = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($mergeDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $rootLen = strlen(rtrim($mergeDir, '/')) + 1;

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $rel = ltrim((string) substr($file->getPathname(), $rootLen), '/');
            if ($rel === '') {
                continue;
            }

            $destPath = $prefix.$rel;
            $stream = fopen($file->getPathname(), 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open restored object for upload: '.$rel);
            }

            try {
                $dest->writeStream($destPath, $stream);
            } finally {
                fclose($stream);
            }
            $uploaded++;
        }

        $logger->log("Restored {$uploaded} object(s) to scope ".($destScope !== '' ? $destScope : '(root)'), 'success');
    }

    /**
     * Delete every existing object under the destination scope prefix. Object
     * storage has no real folders, so removing all matching object keys is
     * sufficient; empty directories left on local adapters are harmless.
     */
    private function wipeDestinationScope(Filesystem $dest, string $prefix): void
    {
        // Remove every existing object under the scope. In object storage there
        // are no real folders, so deleting all matching object keys is enough;
        // empty local adapter directories left behind are harmless.
        foreach ($dest->listContents($prefix, true) as $entry) {
            if ($entry->isFile()) {
                $dest->delete($entry->path());
            }
        }
    }
}
