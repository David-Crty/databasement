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
        Schema::table('database_server_ssh_configs', function (Blueprint $table) {
            $table->boolean('compression')->default(false)->after('auth_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('database_server_ssh_configs', function (Blueprint $table) {
            $table->dropColumn('compression');
        });
    }
};
