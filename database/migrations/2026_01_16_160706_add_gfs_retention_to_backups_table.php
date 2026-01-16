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
            $table->string('retention_policy')->default('simple')->after('retention_days');
            $table->unsignedTinyInteger('keep_daily')->nullable()->after('retention_policy');
            $table->unsignedTinyInteger('keep_weekly')->nullable()->after('keep_daily');
            $table->unsignedTinyInteger('keep_monthly')->nullable()->after('keep_weekly');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn(['retention_policy', 'keep_daily', 'keep_weekly', 'keep_monthly']);
        });
    }
};
