<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_server_notification_channel', function (Blueprint $table) {
            $table->char('database_server_id', 26);
            $table->char('notification_channel_id', 26);

            $table->foreign('database_server_id', 'db_server_notif_ch_server_fk')
                ->references('id')->on('database_servers')
                ->cascadeOnDelete();

            $table->foreign('notification_channel_id', 'db_server_notif_ch_channel_fk')
                ->references('id')->on('notification_channels')
                ->cascadeOnDelete();

            $table->unique(['database_server_id', 'notification_channel_id'], 'db_server_notif_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_server_notification_channel');
    }
};
