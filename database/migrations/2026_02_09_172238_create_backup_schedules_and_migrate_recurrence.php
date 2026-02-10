<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\Ulid;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create backup_schedules table
        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('expression');
            $table->timestamps();
        });

        // 2. Seed initial schedules from existing AppConfig values (or defaults)
        $dailyCron = DB::table('app_configs')->where('id', 'backup.daily_cron')->value('value') ?? '0 2 * * *';
        $weeklyCron = DB::table('app_configs')->where('id', 'backup.weekly_cron')->value('value') ?? '0 3 * * 0';

        $dailyId = (string) Ulid::generate();
        $weeklyId = (string) Ulid::generate();

        DB::table('backup_schedules')->insert([
            ['id' => $dailyId, 'name' => 'Daily', 'expression' => $dailyCron, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $weeklyId, 'name' => 'Weekly', 'expression' => $weeklyCron, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 3. Add backup_schedule_id column to backups table
        Schema::table('backups', function (Blueprint $table) {
            $table->ulid('backup_schedule_id')->nullable()->after('path');
            $table->foreign('backup_schedule_id')->references('id')->on('backup_schedules');
        });

        // 4. Migrate existing recurrence data
        DB::table('backups')->where('recurrence', 'daily')->update(['backup_schedule_id' => $dailyId]);
        DB::table('backups')->where('recurrence', 'weekly')->update(['backup_schedule_id' => $weeklyId]);

        // Assign any remaining backups (e.g. 'manual' or other values) to the daily schedule
        DB::table('backups')->whereNull('backup_schedule_id')->update(['backup_schedule_id' => $dailyId]);

        // 5. Drop recurrence column and make backup_schedule_id non-nullable
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('recurrence');
            $table->dropForeign(['backup_schedule_id']);
            $table->ulid('backup_schedule_id')->nullable(false)->after('path')->change();
            $table->foreign('backup_schedule_id')->references('id')->on('backup_schedules');
        });

        // 6. Remove daily_cron and weekly_cron from app_configs
        DB::table('app_configs')->whereIn('id', ['backup.daily_cron', 'backup.weekly_cron'])->delete();
    }

    public function down(): void
    {
        // Re-add recurrence column
        Schema::table('backups', function (Blueprint $table) {
            $table->string('recurrence')->default('daily')->after('path');
        });

        // Migrate data back
        $dailySchedule = DB::table('backup_schedules')->where('name', 'Daily')->first();
        $weeklySchedule = DB::table('backup_schedules')->where('name', 'Weekly')->first();

        if ($dailySchedule) {
            DB::table('backups')->where('backup_schedule_id', $dailySchedule->id)->update(['recurrence' => 'daily']);
        }
        if ($weeklySchedule) {
            DB::table('backups')->where('backup_schedule_id', $weeklySchedule->id)->update(['recurrence' => 'weekly']);
        }

        // Re-insert AppConfig rows
        $dailyCron = $dailySchedule?->expression ?? '0 2 * * *';
        $weeklyCron = $weeklySchedule?->expression ?? '0 3 * * 0';

        DB::table('app_configs')->insert([
            ['id' => 'backup.daily_cron', 'value' => $dailyCron, 'type' => 'string', 'is_sensitive' => false, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'backup.weekly_cron', 'value' => $weeklyCron, 'type' => 'string', 'is_sensitive' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Drop FK and column
        Schema::table('backups', function (Blueprint $table) {
            $table->dropForeign(['backup_schedule_id']);
            $table->dropColumn('backup_schedule_id');
        });

        Schema::dropIfExists('backup_schedules');
    }
};
