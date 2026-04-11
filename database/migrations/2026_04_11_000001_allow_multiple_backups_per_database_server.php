<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert DatabaseServer → Backup from 1:1 to 1:N and move ALL
     * database-targeting fields (selection_mode, names, include_pattern)
     * from the server to each backup config. A server can now own multiple
     * independent backup configurations with distinct schedules, volumes,
     * retention policies and database selections.
     *
     * For SQLite, the `database_names` column held the file paths; with
     * this change those paths also live on the Backup model for consistency
     * with client-server backups. One server → many backups, each backup
     * picks its own file(s) or database set.
     */
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->string('database_selection_mode')->default('all')->after('gfs_keep_monthly');
            $table->json('database_names')->nullable()->after('database_selection_mode');
            $table->string('database_include_pattern')->nullable()->after('database_names');
        });

        // Loop in PHP rather than a correlated UPDATE so the data copy stays
        // portable across MySQL and PostgreSQL.
        $servers = DB::table('database_servers')
            ->get(['id', 'database_type', 'database_selection_mode', 'database_names', 'database_include_pattern'])
            ->keyBy('id');

        DB::table('backups')
            ->orderBy('id')
            ->chunkById(200, function ($backups) use ($servers) {
                foreach ($backups as $backup) {
                    $server = $servers->get($backup->database_server_id);
                    if ($server === null) {
                        continue;
                    }

                    $mode = $server->database_type === 'sqlite'
                        ? 'selected'
                        : ($server->database_selection_mode ?? 'all');

                    DB::table('backups')
                        ->where('id', $backup->id)
                        ->update([
                            'database_selection_mode' => $mode,
                            'database_names' => $server->database_names,
                            'database_include_pattern' => $server->database_include_pattern,
                        ]);
                }
            });

        Schema::table('database_servers', function (Blueprint $table) {
            $table->dropColumn([
                'database_selection_mode',
                'database_include_pattern',
                'database_names',
            ]);
        });

        // Drop the FK before the unique so MySQL doesn't complain about an
        // index still being needed by the constraint.
        Schema::table('backups', function (Blueprint $table) {
            $table->dropForeign(['database_server_id']);
            $table->dropUnique(['database_server_id']);
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->foreign('database_server_id')
                ->references('id')
                ->on('database_servers')
                ->onDelete('cascade');
        });

        // Historical snapshots outlive their backup config: nullOnDelete so
        // removing a config doesn't wipe its existing snapshots.
        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropForeign(['backup_id']);
        });

        Schema::table('snapshots', function (Blueprint $table) {
            $table->char('backup_id', 26)->nullable()->change();
            $table->foreign('backup_id')
                ->references('id')
                ->on('backups')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migration. The downgrade assumes the "one backup per server"
     * invariant — if a server has multiple backups, only the first row is used
     * to restore the server-level selection fields.
     */
    public function down(): void
    {
        // Orphaned snapshots (with null backup_id) must go before restoring
        // the NOT NULL + cascade constraint, otherwise the column change fails.
        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropForeign(['backup_id']);
        });

        DB::table('snapshots')->whereNull('backup_id')->delete();

        Schema::table('snapshots', function (Blueprint $table) {
            $table->char('backup_id', 26)->nullable(false)->change();
            $table->foreign('backup_id')
                ->references('id')
                ->on('backups')
                ->cascadeOnDelete();
        });

        Schema::table('backups', function (Blueprint $table) {
            $table->dropForeign(['database_server_id']);
        });

        // Collapse any duplicate backups per server before restoring the
        // UNIQUE(database_server_id) constraint. The earliest row (min id)
        // wins; the rest are deleted so the unique index can be created.
        $keepIds = DB::table('backups')
            ->selectRaw('MIN(id) as id')
            ->groupBy('database_server_id')
            ->pluck('id')
            ->all();

        if ($keepIds !== []) {
            DB::table('backups')->whereNotIn('id', $keepIds)->delete();
        }

        Schema::table('backups', function (Blueprint $table) {
            $table->unique('database_server_id');
            $table->foreign('database_server_id')
                ->references('id')
                ->on('database_servers')
                ->onDelete('cascade');
        });

        Schema::table('database_servers', function (Blueprint $table) {
            $table->json('database_names')->nullable()->after('password');
            $table->string('database_selection_mode')->default('all')->after('database_names');
            $table->string('database_include_pattern')->nullable()->after('database_selection_mode');
        });

        DB::table('backups')
            ->orderBy('id')
            ->chunkById(200, function ($backups) {
                foreach ($backups as $backup) {
                    DB::table('database_servers')
                        ->where('id', $backup->database_server_id)
                        ->update([
                            'database_selection_mode' => $backup->database_selection_mode ?? 'all',
                            'database_names' => $backup->database_names,
                            'database_include_pattern' => $backup->database_include_pattern,
                        ]);
                }
            });

        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn([
                'database_selection_mode',
                'database_names',
                'database_include_pattern',
            ]);
        });
    }
};
