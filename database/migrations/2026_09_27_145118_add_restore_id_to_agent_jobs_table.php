<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->char('restore_id', 26)->nullable()->after('snapshot_id');
            $table->foreign('restore_id')->references('id')->on('restores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agent_jobs', function (Blueprint $table) {
            $table->dropForeign(['restore_id']);
            $table->dropColumn('restore_id');
        });
    }
};
