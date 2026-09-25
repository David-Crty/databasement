<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bind a volume to the agent that can reach it.
 *
 * Volumes living on a private network are unreachable from the app, so their
 * connection test has to run on the agent instead. Null keeps the existing
 * behaviour: the app tests the volume itself.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('volumes', function (Blueprint $table) {
            $table->char('agent_id', 26)->nullable()->after('type');
            $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('volumes', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropColumn('agent_id');
        });
    }
};
