<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->dateTime('last_heartbeat_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_jobs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('agent_id', 26)->nullable();
            $table->char('snapshot_id', 26);
            $table->enum('status', ['pending', 'claimed', 'running', 'completed', 'failed'])->default('pending');
            $table->json('payload');
            $table->dateTime('lease_expires_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('logs')->nullable();
            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
            $table->foreign('snapshot_id')->references('id')->on('snapshots')->cascadeOnDelete();
            $table->index(['status', 'lease_expires_at']);
        });

        Schema::table('database_servers', function (Blueprint $table) {
            $table->char('agent_id', 26)->nullable()->after('ssh_config_id');
            $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
        });

        // Change tokenable_id to string to support ULID-based models (agents)
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('tokenable_id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('database_servers', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropColumn('agent_id');
        });

        Schema::dropIfExists('agent_jobs');
        Schema::dropIfExists('agents');

        // Delete agent-scoped tokens before changing column type to avoid ULID-to-integer coercion
        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\Agent')
            ->delete();

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('tokenable_id')->change();
        });
    }
};
