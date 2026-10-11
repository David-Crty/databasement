<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Link snapshots that lost their backup configuration to their server's
     * configuration, when the server has exactly one, so retention applies
     * to them again.
     */
    public function up(): void
    {
        DB::table('backups')
            ->selectRaw('database_server_id, MIN(id) as id')
            ->groupBy('database_server_id')
            ->havingRaw('COUNT(*) = 1')
            ->get()
            ->each(function (object $backup) {
                DB::table('snapshots')
                    ->where('database_server_id', $backup->database_server_id)
                    ->whereNull('backup_id')
                    ->update(['backup_id' => $backup->id]);
            });
    }

    /**
     * The snapshots that were orphaned cannot be told apart once linked.
     */
    public function down(): void
    {
        //
    }
};
