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
        Schema::create('snapshots', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('backup_job_id', 26)->index('snapshots_backup_job_id_foreign');
            $table->char('database_server_id', 26)->index('snapshots_database_server_id_foreign');
            $table->char('backup_id', 26)->index('snapshots_backup_id_foreign');
            $table->char('volume_id', 26)->index('snapshots_volume_id_foreign');
            $table->string('path');
            $table->unsignedBigInteger('file_size');
            $table->string('checksum')->nullable();
            $table->timestamp('started_at');
            $table->string('database_name');
            $table->string('database_type');
            $table->string('database_host');
            $table->integer('database_port');
            $table->string('compression_type')->default('gzip');
            $table->enum('method', ['manual', 'scheduled']);
            $table->unsignedBigInteger('triggered_by_user_id')->nullable()->index('snapshots_triggered_by_user_id_foreign');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('snapshots');
    }
};
