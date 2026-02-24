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
        Schema::table('database_servers', function (Blueprint $table) {
            $table->string('database_selection_mode')->default('all')->after('database_names');
            $table->string('database_include_pattern')->nullable()->after('database_selection_mode');
        });

        // Migrate existing data: backup_all_databases=true -> 'all', else -> 'selected'
        DB::table('database_servers')
            ->where('backup_all_databases', true)
            ->update(['database_selection_mode' => 'all']);

        DB::table('database_servers')
            ->where('backup_all_databases', false)
            ->update(['database_selection_mode' => 'selected']);

        Schema::table('database_servers', function (Blueprint $table) {
            $table->dropColumn('backup_all_databases');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('database_servers', function (Blueprint $table) {
            $table->boolean('backup_all_databases')->default(false)->after('database_names');
        });

        DB::table('database_servers')
            ->where('database_selection_mode', 'all')
            ->update(['backup_all_databases' => true]);

        DB::table('database_servers')
            ->whereIn('database_selection_mode', ['selected', 'pattern'])
            ->update(['backup_all_databases' => false]);

        Schema::table('database_servers', function (Blueprint $table) {
            $table->dropColumn(['database_selection_mode', 'database_include_pattern']);
        });
    }
};
