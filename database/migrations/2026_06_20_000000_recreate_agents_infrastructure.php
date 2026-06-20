<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recreate the agent tables and the database_servers.agent_id column.
     *
     * The agent feature was removed in #365 via a forward-only drop migration.
     * Restoring the feature (#388) means databases that already ran that drop
     * have lost these tables, while fresh installs still build them through the
     * original create migration. The guards make this a no-op on fresh installs
     * and a recreate on databases that dropped the agent infrastructure.
     */
    public function up(): void
    {
        if (! Schema::hasTable('agents')) {
            Schema::create('agents', function (Blueprint $table) {
                $table->char('id', 26)->primary();
                $table->char('organization_id', 26);
                $table->string('name');
                $table->dateTime('last_heartbeat_at')->nullable();
                $table->timestamps();

                $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('agent_jobs')) {
            Schema::create('agent_jobs', function (Blueprint $table) {
                $table->char('id', 26)->primary();
                $table->string('type')->default('backup');
                $table->char('agent_id', 26)->nullable();
                $table->char('database_server_id', 26)->nullable();
                $table->char('snapshot_id', 26)->nullable();
                $table->enum('status', ['pending', 'claimed', 'running', 'completed', 'failed'])->default('pending');
                $table->longText('payload');
                $table->dateTime('lease_expires_at')->nullable();
                $table->integer('attempts')->default(0);
                $table->integer('max_attempts')->default(3);
                $table->dateTime('claimed_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->json('logs')->nullable();
                $table->timestamps();

                $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
                $table->foreign('database_server_id')->references('id')->on('database_servers')->cascadeOnDelete();
                $table->foreign('snapshot_id')->references('id')->on('snapshots')->cascadeOnDelete();
                $table->index(['status', 'lease_expires_at']);
            });
        }

        if (! Schema::hasColumn('database_servers', 'agent_id')) {
            Schema::table('database_servers', function (Blueprint $table) {
                $table->char('agent_id', 26)->nullable();
                $table->foreign('agent_id')->references('id')->on('agents')->nullOnDelete();
            });
        }
    }

    public function down(): void
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
};
