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
            $table->boolean('file_exists')->default(true)->after('file_size')->index();
            $table->timestamp('file_verified_at')->nullable()->after('file_exists');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('snapshots', function (Blueprint $table) {
            $table->dropColumn(['file_exists', 'file_verified_at']);
        });
    }
};
