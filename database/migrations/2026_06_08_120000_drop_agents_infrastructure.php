<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('database_servers', 'agent_id')) {
            Schema::table('database_servers', function (Blueprint $table) {
                $table->dropForeign(['agent_id']);
                $table->dropColumn('agent_id');
            });
        }

        Schema::dropIfExists('agent_jobs');
        Schema::dropIfExists('agents');
    }

    public function down(): void
    {
        // No-op: the agent feature is removed and not coming back.
    }
};
