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
        // Copy sqlite_path into database_names for all SQLite servers
        DB::table('database_servers')
            ->where('database_type', 'sqlite')
            ->whereNotNull('sqlite_path')
            ->where('sqlite_path', '!=', '')
            ->eachById(function ($server) {
                DB::table('database_servers')
                    ->where('id', $server->id)
                    ->update(['database_names' => json_encode([$server->sqlite_path])]);
            });

        Schema::table('database_servers', function (Blueprint $table) {
            $table->dropColumn('sqlite_path');
            $table->json('extra_config')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('database_servers', function (Blueprint $table) {
            $table->string('sqlite_path')->nullable()->after('database_type');
            $table->dropColumn('extra_config');
        });

        // Copy database_names[0] back to sqlite_path for SQLite servers
        DB::table('database_servers')
            ->where('database_type', 'sqlite')
            ->whereNotNull('database_names')
            ->eachById(function ($server) {
                $names = json_decode($server->database_names, true);
                if (! empty($names[0])) {
                    DB::table('database_servers')
                        ->where('id', $server->id)
                        ->update(['sqlite_path' => $names[0]]);
                }
            });
    }
};
