<?php

namespace App\Models;

use Database\Factories\SnapshotObjectFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One object changed by a single bucket-copy backup run. Records the relative
 * in-source path together with the size/mtime/checksum read at archive time so
 * a later run can diff against it. Deleted source objects are stored as
 * tombstone rows (size/mtime null, {@see self::$tombstone} true) so a restore
 * of the run's state drops them without resurrecting older versions.
 */
class SnapshotObjectFile extends Model
{
    /** @use HasFactory<SnapshotObjectFileFactory> */
    use HasFactory;

    protected $fillable = [
        'snapshot_id',
        'path',
        'size',
        'mtime',
        'checksum',
        'tombstone',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'mtime' => 'datetime',
            'tombstone' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Snapshot, SnapshotObjectFile>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(Snapshot::class);
    }
}
