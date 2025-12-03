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
        Schema::create('restores', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('backup_job_id', 26)->index('restores_backup_job_id_foreign');
            $table->char('snapshot_id', 26)->index('restores_snapshot_id_foreign');
            $table->char('target_server_id', 26)->index('restores_target_server_id_foreign');
            $table->string('schema_name');
            $table->unsignedBigInteger('triggered_by_user_id')->nullable()->index('restores_triggered_by_user_id_foreign');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restores');
    }
};
