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
        Schema::create('database_servers', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('name');
            $table->string('host');
            $table->integer('port')->default(3306);
            $table->string('database_type')->default('mysql');
            $table->string('username');
            $table->string('password');
            $table->string('database_name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('database_servers');
    }
};
