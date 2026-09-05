<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the bucket-copy run bookkeeping used by S3/object-storage servers.
     *
     * A bucket backup is stored as a chain of per-run tar archives on the
     * destination volume(s): a periodic "full" archive contains every object
     * in the source folder; the "incremental" archives between two full runs
     * carry only the objects that changed since the archive they build on.
     * Each run is one row in `snapshots` (same shape as a SQL snapshot: a
     * filename/size/checksum in the volume) plus one row in
     * `snapshot_object_files` per object changed by that run.
     *
     * Ordinary SQL snapshots leave both columns null (no archive-packing, no
     * chain) so existing rows and the SQL pipeline are untouched.
     */
    public function up(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            // 'full' | 'incremental' | null (null = a plain SQL snapshot).
            $table->string('run_kind', 20)->nullable()->after('compression_type');

            // The first {'full'} run this incremental chain builds on. Null for
            // full runs and for SQL snapshots. Carried so a restore can resolve
            // the set of archives (anchor + incrementals) to download — an
            // incremental never removes a file its predecessors still needed,
            // so an archive older than the newest full can't be trimmed without
            // breaking newer restores.
            $table->char('full_snapshot_id', 26)->nullable()->after('run_kind');
        });

        Schema::table('snapshots', function (Blueprint $table) {
            $table->foreign('full_snapshot_id')->references('id')->on('snapshots')->nullOnDelete();
        });

        // One row per object the run archives: the in-source relative path, its
        // size/mtime at read time, and its checksum. A "deleted" source object
        // is recorded as a tombstone row (size/mtime null, tombstone=1) so a
        // restore of the run's state skips it without resurrecting older data.
        Schema::create('snapshot_object_files', function (Blueprint $table) {
            $table->id();
            $table->char('snapshot_id', 26);
            $table->string('path');
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamp('mtime')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->boolean('tombstone')->default(false);
            $table->timestamps();

            $table->foreign('snapshot_id')->references('id')->on('snapshots')->cascadeOnDelete();
            $table->index(['snapshot_id', 'path']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropForeign(['full_snapshot_id']);
        });

        Schema::dropIfExists('snapshot_object_files');

        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropColumn(['run_kind', 'full_snapshot_id']);
        });
    }
};
