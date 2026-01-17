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
        Schema::table('backups', function (Blueprint $table) {
            $table->string('retention_policy')->default('days')->after('retention_days');
            $table->unsignedTinyInteger('gfs_keep_daily')->nullable()->after('retention_policy');
            $table->unsignedTinyInteger('gfs_keep_weekly')->nullable()->after('gfs_keep_daily');
            $table->unsignedTinyInteger('gfs_keep_monthly')->nullable()->after('gfs_keep_weekly');
        });

        // Migrate existing backups with no retention_days to 'forever' policy
        DB::table('backups')
            ->whereNull('retention_days')
            ->update(['retention_policy' => 'forever']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['retention_policy', 'gfs_keep_daily', 'gfs_keep_weekly', 'gfs_keep_monthly']);
        });
    }
};
