<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_server_accesses', function (Blueprint $table) {
            $table->boolean('can_backup')->default(false)->after('can_download');
        });
    }

    public function down(): void
    {
        Schema::table('user_server_accesses', function (Blueprint $table) {
            $table->dropColumn('can_backup');
        });
    }
};
