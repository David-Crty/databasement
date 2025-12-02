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
            $table->dropColumn('database_size_bytes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('database_size_bytes')->nullable()->after('database_port');
        });
    }
};
