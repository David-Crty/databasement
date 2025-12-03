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
        Schema::table('backups', function (Blueprint $table) {
            $table->foreign(['database_server_id'])->references(['id'])->on('database_servers')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['volume_id'])->references(['id'])->on('volumes')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropForeign('backups_database_server_id_foreign');
            $table->dropForeign('backups_volume_id_foreign');
        });
    }
};
