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
        Schema::create('backups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('database_server_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('volume_id')->constrained()->cascadeOnDelete();
            $table->string('recurrence')->default('daily'); // daily, weekly
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
