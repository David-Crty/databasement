<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Truncate tables to avoid foreign key issues
        DB::table('restores')->truncate();
        DB::table('snapshots')->truncate();
        DB::table('backup_jobs')->truncate();

        // Remove old foreign keys from backup_jobs
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropForeign(['snapshot_id']);
            $table->dropForeign(['restore_id']);
            $table->dropColumn(['snapshot_id', 'restore_id']);
        });

        // Add backup_job_id to snapshots (NOT NULL - always required)
        Schema::table('snapshots', function (Blueprint $table) {
            $table->foreignUlid('backup_job_id')->after('id')->constrained()->cascadeOnDelete();
        });

        // Add backup_job_id to restores (NOT NULL - always required)
        Schema::table('restores', function (Blueprint $table) {
            $table->foreignUlid('backup_job_id')->after('id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Truncate tables to avoid foreign key issues
        DB::table('restores')->truncate();
        DB::table('snapshots')->truncate();
        DB::table('backup_jobs')->truncate();

        // Remove backup_job_id from snapshots
        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropForeign(['backup_job_id']);
            $table->dropColumn('backup_job_id');
        });

        // Remove backup_job_id from restores
        Schema::table('restores', function (Blueprint $table) {
            $table->dropForeign(['backup_job_id']);
            $table->dropColumn('backup_job_id');
        });

        // Re-add foreign keys to backup_jobs
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->foreignUlid('snapshot_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('restore_id')->nullable()->after('snapshot_id')->constrained()->cascadeOnDelete();
        });
    }
};
