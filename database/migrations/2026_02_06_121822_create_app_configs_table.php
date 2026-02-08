<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('app_configs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->boolean('is_sensitive')->default(false);
            $table->timestamps();
        });

        $this->seed();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_configs');
    }

    private function seed(): void
    {
        $rows = [
            ['id' => 'backup.working_directory', 'value' => env('BACKUP_WORKING_DIRECTORY', '/tmp/backups'), 'type' => 'string', 'is_sensitive' => false],
            ['id' => 'backup.compression', 'value' => env('BACKUP_COMPRESSION', 'gzip'), 'type' => 'string', 'is_sensitive' => false],
            ['id' => 'backup.compression_level', 'value' => (string) env('BACKUP_COMPRESSION_LEVEL', 6), 'type' => 'integer', 'is_sensitive' => false],
            ['id' => 'backup.job_timeout', 'value' => (string) env('BACKUP_JOB_TIMEOUT', 7200), 'type' => 'integer', 'is_sensitive' => false],
            ['id' => 'backup.job_tries', 'value' => (string) env('BACKUP_JOB_TRIES', 3), 'type' => 'integer', 'is_sensitive' => false],
            ['id' => 'backup.job_backoff', 'value' => (string) env('BACKUP_JOB_BACKOFF', 60), 'type' => 'integer', 'is_sensitive' => false],
            ['id' => 'backup.daily_cron', 'value' => env('BACKUP_DAILY_CRON', '0 2 * * *'), 'type' => 'string', 'is_sensitive' => false],
            ['id' => 'backup.weekly_cron', 'value' => env('BACKUP_WEEKLY_CRON', '0 3 * * 0'), 'type' => 'string', 'is_sensitive' => false],
            ['id' => 'backup.cleanup_cron', 'value' => env('BACKUP_CLEANUP_CRON', '0 4 * * *'), 'type' => 'string', 'is_sensitive' => false],
            ['id' => 'backup.verify_files', 'value' => env('BACKUP_VERIFY_FILES', true) ? '1' : '0', 'type' => 'boolean', 'is_sensitive' => false],
            ['id' => 'backup.verify_files_cron', 'value' => env('BACKUP_VERIFY_FILES_CRON', '0 5 * * *'), 'type' => 'string', 'is_sensitive' => false],
            ['id' => 'notifications.enabled', 'value' => env('NOTIFICATION_ENABLED', false) ? '1' : '0', 'type' => 'boolean', 'is_sensitive' => false],
            ['id' => 'notifications.mail.to', 'value' => env('NOTIFICATION_MAIL_TO'), 'type' => 'string', 'is_sensitive' => false],
            ['id' => 'notifications.slack.webhook_url', 'value' => env('NOTIFICATION_SLACK_WEBHOOK_URL'), 'type' => 'string', 'is_sensitive' => true],
            ['id' => 'notifications.discord.token', 'value' => env('NOTIFICATION_DISCORD_BOT_TOKEN'), 'type' => 'string', 'is_sensitive' => true],
            ['id' => 'notifications.discord.channel_id', 'value' => env('NOTIFICATION_DISCORD_CHANNEL_ID'), 'type' => 'string', 'is_sensitive' => false],
        ];

        foreach ($rows as $row) {
            if ($row['is_sensitive'] && $row['value'] !== null && $row['value'] !== '') {
                $row['value'] = Crypt::encryptString($row['value']);
            }

            DB::table('app_configs')->insert([
                'id' => $row['id'],
                'value' => $row['value'],
                'type' => $row['type'],
                'is_sensitive' => $row['is_sensitive'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
