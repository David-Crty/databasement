<?php

namespace App\Support;

use App\Models\Snapshot;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Per-chain mutual exclusion between retention cleanup and the backup job.
 *
 * An S3 bucket-copy run is not self-contained: an incremental is only
 * restorable while every earlier run of its chain (the anchor full and the
 * prior incrementals) still exists. SnapshotCleanupService therefore checks
 * for retained descendants before deleting an expired chain run, and only
 * then bypasses the delete-newest-first guard. If ProcessBackupJob persisted a
 * new run into the same chain between that check and the delete, the deleted
 * anchor/prior run would silently orphan it (the nullOnDelete FK clears its
 * full_snapshot_id), leaving it unrestorable.
 *
 * Both sides therefore hold the same per-chain lock: cleanup for the duration
 * of one decide-and-delete, the backup job from the moment it picks its
 * lineage until the run is persisted. The other side simply waits or defers,
 * so the check/delete and the lineage write can never interleave.
 */
final class SnapshotChainLock
{
    /**
     * How long cleanup may hold a chain lock. Cleanup only ever holds it for a
     * single decide-and-delete (milliseconds), so a short TTL is enough; the
     * job side uses its own run timeout as the TTL.
     */
    public const int CLEANUP_TTL_SECONDS = 60;

    /**
     * The shared lock key for one chain (one folder scope on one server).
     */
    public static function key(string|int $databaseServerId, ?string $databaseName): string
    {
        return 'snapshot-chain:'.$databaseServerId.':'.(string) $databaseName;
    }

    /**
     * The chain lock for a snapshot's chain, valid for the given TTL.
     */
    public static function forSnapshot(Snapshot $snapshot, int $ttlSeconds): Lock
    {
        return Cache::lock(self::key($snapshot->database_server_id, $snapshot->database_name), $ttlSeconds);
    }
}
