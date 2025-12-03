<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('restores', function (Blueprint $table) {
            $table->foreign(['backup_job_id'])->references(['id'])->on('backup_jobs')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['snapshot_id'])->references(['id'])->on('snapshots')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['target_server_id'])->references(['id'])->on('database_servers')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['triggered_by_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restores', function (Blueprint $table) {
            $table->dropForeign('restores_backup_job_id_foreign');
            $table->dropForeign('restores_snapshot_id_foreign');
            $table->dropForeign('restores_target_server_id_foreign');
            $table->dropForeign('restores_triggered_by_user_id_foreign');
        });
    }
};
