<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_server_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('database_server_id', 26);
            $table->foreign('database_server_id')->references('id')->on('database_servers')->cascadeOnDelete();
            $table->json('allowed_databases')->nullable()->comment('Null means all databases on this server');
            $table->boolean('can_download')->default(true);
            $table->boolean('can_restore')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'database_server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_server_accesses');
    }
};
