<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_restores')->whereNull('source_database_name')->delete();

        Schema::table('scheduled_restores', function (Blueprint $table) {
            $table->string('source_database_name')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_restores', function (Blueprint $table) {
            $table->string('source_database_name')->nullable()->change();
        });
    }
};
