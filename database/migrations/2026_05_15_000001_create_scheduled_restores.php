<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_restores', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->ulid('source_server_id');
            $table->string('source_database_name')->nullable();
            $table->ulid('target_server_id');
            $table->string('schema_name');
            $table->ulid('backup_schedule_id');
            $table->json('options')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_executed_at')->nullable();
            $table->string('last_skip_reason')->nullable();
            $table->timestamps();

            $table->foreign('source_server_id')->references('id')->on('database_servers')->cascadeOnDelete();
            $table->foreign('target_server_id')->references('id')->on('database_servers')->cascadeOnDelete();
            $table->foreign('backup_schedule_id')->references('id')->on('backup_schedules')->restrictOnDelete();

            $table->index(['enabled', 'source_server_id']);
        });

        Schema::table('restores', function (Blueprint $table) {
            $table->ulid('scheduled_restore_id')->nullable()->after('triggered_by_user_id');
            $table->foreign('scheduled_restore_id')->references('id')->on('scheduled_restores')->nullOnDelete();
            $table->index('scheduled_restore_id');
        });
    }

    public function down(): void
    {
        Schema::table('restores', function (Blueprint $table) {
            $table->dropForeign(['scheduled_restore_id']);
            $table->dropIndex(['scheduled_restore_id']);
            $table->dropColumn('scheduled_restore_id');
        });

        Schema::dropIfExists('scheduled_restores');
    }
};
