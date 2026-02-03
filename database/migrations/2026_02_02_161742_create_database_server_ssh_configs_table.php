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
        Schema::create('database_server_ssh_configs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('host');
            $table->integer('port')->default(22);
            $table->string('username');
            $table->string('auth_type'); // 'password' or 'key'
            $table->text('password')->nullable(); // encrypted
            $table->text('private_key')->nullable(); // encrypted
            $table->text('key_passphrase')->nullable(); // encrypted
            $table->timestamps();
        });

        Schema::table('database_servers', function (Blueprint $table) {
            $table->ulid('ssh_config_id')->nullable()->after('backups_enabled');
            $table->foreign('ssh_config_id')
                ->references('id')
                ->on('database_server_ssh_configs')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('database_servers', function (Blueprint $table) {
            $table->dropForeign(['ssh_config_id']);
            $table->dropColumn('ssh_config_id');
        });

        Schema::dropIfExists('database_server_ssh_configs');
    }
};
