<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_servers', function (Blueprint $table) {
            $table->string('notification_trigger')->default('failure');
            $table->string('notification_channel_selection')->default('all');
        });
    }

    public function down(): void
    {
        Schema::table('database_servers', function (Blueprint $table) {
            $table->dropColumn(['notification_trigger', 'notification_channel_selection']);
        });
    }
};
