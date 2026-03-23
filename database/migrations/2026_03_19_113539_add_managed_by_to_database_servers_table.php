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
        Schema::table('database_servers', function (Blueprint $table) {
            $table->string('managed_by')->nullable()->index()->after('agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('database_servers', function (Blueprint $table) {
            $table->dropColumn('managed_by');
        });
    }
};
