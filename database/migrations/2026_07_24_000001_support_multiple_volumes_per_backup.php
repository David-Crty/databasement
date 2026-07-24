<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * One Backup config can now target multiple storage volumes: the database
     * is dumped once and the archive is uploaded to each volume.
     *
     * - `backup_volume` (pivot) replaces `backups.volume_id`.
     * - `snapshot_files` (one row per snapshot × volume copy, with per-copy
     *   upload status and file verification state) replaces
     *   `snapshots.volume_id`. Archive-level fields (filename, file_size,
     *   checksum) stay on `snapshots` — the file is identical on every volume.
     * - `restores.snapshot_file_id` records which copy a restore reads from
     *   (null = auto-pick the first available copy).
     */
    public function up(): void
    {
        Schema::create('backup_volume', function (Blueprint $table) {
            $table->id();
            $table->char('backup_id', 26);
            $table->char('volume_id', 26);
            $table->foreign('backup_id')->references('id')->on('backups')->cascadeOnDelete();
            $table->foreign('volume_id')->references('id')->on('volumes')->cascadeOnDelete();
            $table->unique(['backup_id', 'volume_id']);
        });

        Schema::create('snapshot_files', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('snapshot_id', 26);
            $table->char('volume_id', 26);
            $table->string('filename')->default('');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('status')->default('pending');
            $table->boolean('file_exists')->default(true);
            $table->timestamp('file_verified_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->foreign('snapshot_id')->references('id')->on('snapshots')->cascadeOnDelete();
            $table->foreign('volume_id')->references('id')->on('volumes')->cascadeOnDelete();
            $table->unique(['snapshot_id', 'volume_id']);
            $table->index(['volume_id', 'status']);
        });

        // Loop in PHP rather than INSERT…SELECT so the data copy stays
        // portable across MySQL, PostgreSQL and SQLite.
        DB::table('backups')->orderBy('id')->chunkById(200, function ($backups) {
            $rows = [];
            foreach ($backups as $backup) {
                if ($backup->volume_id === null) {
                    continue;
                }
                $rows[] = ['backup_id' => $backup->id, 'volume_id' => $backup->volume_id];
            }
            if ($rows !== []) {
                DB::table('backup_volume')->insert($rows);
            }
        });

        $now = now();
        DB::table('snapshots')->orderBy('id')->chunkById(200, function ($snapshots) use ($now) {
            $rows = [];
            foreach ($snapshots as $snapshot) {
                if ($snapshot->volume_id === null) {
                    continue;
                }
                $hasFile = $snapshot->filename !== null && $snapshot->filename !== '';
                $rows[] = [
                    'id' => strtolower((string) Str::ulid()),
                    'snapshot_id' => $snapshot->id,
                    'volume_id' => $snapshot->volume_id,
                    'filename' => $snapshot->filename ?? '',
                    'file_size' => $snapshot->file_size ?? 0,
                    'status' => $hasFile ? 'completed' : 'pending',
                    'file_exists' => (bool) ($snapshot->file_exists ?? true),
                    'file_verified_at' => $snapshot->file_verified_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows !== []) {
                DB::table('snapshot_files')->insert($rows);
            }
        });

        Schema::table('restores', function (Blueprint $table) {
            $table->char('snapshot_file_id', 26)->nullable()->after('snapshot_id');
            $table->foreign('snapshot_file_id')->references('id')->on('snapshot_files')->nullOnDelete();
        });

        // Drop the FK and the explicitly-named index in their own calls
        // before the column so every driver (notably SQLite, which cannot
        // drop a column still referenced by an index) handles it correctly.
        Schema::table('backups', function (Blueprint $table) {
            $table->dropForeign(['volume_id']);
        });
        Schema::table('backups', function (Blueprint $table) {
            $table->dropIndex('backups_volume_id_foreign');
        });
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('volume_id');
        });

        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropForeign(['volume_id']);
        });
        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropIndex('snapshots_volume_id_foreign');
        });
        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropColumn('volume_id');
        });
    }

    /**
     * Reverse the migration. Best-effort: multi-volume rows collapse to the
     * first (oldest) volume; snapshots/backups left without any volume are
     * deleted so the NOT NULL + FK constraints can be restored.
     */
    public function down(): void
    {
        Schema::table('restores', function (Blueprint $table) {
            $table->dropForeign(['snapshot_file_id']);
        });
        Schema::table('restores', function (Blueprint $table) {
            $table->dropColumn('snapshot_file_id');
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->char('volume_id', 26)->nullable()->index('backups_volume_id_foreign');
        });
        Schema::table('snapshots', function (Blueprint $table) {
            $table->char('volume_id', 26)->nullable()->index('snapshots_volume_id_foreign');
        });

        DB::table('backup_volume')
            ->selectRaw('backup_id, MIN(volume_id) as volume_id')
            ->groupBy('backup_id')
            ->orderBy('backup_id')
            ->chunkById(200, function ($pivots) {
                foreach ($pivots as $pivot) {
                    DB::table('backups')->where('id', $pivot->backup_id)->update(['volume_id' => $pivot->volume_id]);
                }
            }, 'backup_id');

        DB::table('snapshot_files')
            ->selectRaw('snapshot_id, MIN(volume_id) as volume_id')
            ->groupBy('snapshot_id')
            ->orderBy('snapshot_id')
            ->chunkById(200, function ($files) {
                foreach ($files as $file) {
                    DB::table('snapshots')->where('id', $file->snapshot_id)->update(['volume_id' => $file->volume_id]);
                }
            }, 'snapshot_id');

        DB::table('restores')->whereIn('snapshot_id', DB::table('snapshots')->whereNull('volume_id')->pluck('id'))->delete();
        DB::table('snapshots')->whereNull('volume_id')->delete();
        DB::table('backups')->whereNull('volume_id')->delete();

        Schema::table('backups', function (Blueprint $table) {
            $table->char('volume_id', 26)->nullable(false)->change();
            $table->foreign(['volume_id'])->references(['id'])->on('volumes')->onUpdate('no action')->onDelete('cascade');
        });
        Schema::table('snapshots', function (Blueprint $table) {
            $table->char('volume_id', 26)->nullable(false)->change();
            $table->foreign(['volume_id'])->references(['id'])->on('volumes')->onUpdate('no action')->onDelete('cascade');
        });

        Schema::dropIfExists('snapshot_files');
        Schema::dropIfExists('backup_volume');
    }
};
