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
        Schema::table('snapshots', function (Blueprint $table) {
            $table->foreign(['backup_id'])->references(['id'])->on('backups')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['backup_job_id'])->references(['id'])->on('backup_jobs')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['database_server_id'])->references(['id'])->on('database_servers')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['triggered_by_user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['volume_id'])->references(['id'])->on('volumes')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropForeign('snapshots_backup_id_foreign');
            $table->dropForeign('snapshots_backup_job_id_foreign');
            $table->dropForeign('snapshots_database_server_id_foreign');
            $table->dropForeign('snapshots_triggered_by_user_id_foreign');
            $table->dropForeign('snapshots_volume_id_foreign');
        });
    }
};
