<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Normalize postgresql -> postgres
        DB::table('database_servers')
            ->where('database_type', 'postgresql')
            ->update(['database_type' => 'postgres']);

        // Normalize mariadb -> mysql
        DB::table('database_servers')
            ->where('database_type', 'mariadb')
            ->update(['database_type' => 'mysql']);

        // Also update snapshots table
        DB::table('snapshots')
            ->where('database_type', 'postgresql')
            ->update(['database_type' => 'postgres']);

        DB::table('snapshots')
            ->where('database_type', 'mariadb')
            ->update(['database_type' => 'mysql']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration normalizes values - no reliable way to reverse
        // as we can't distinguish which 'mysql' was originally 'mariadb'
    }
};
