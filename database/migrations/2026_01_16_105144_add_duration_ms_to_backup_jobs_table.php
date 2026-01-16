<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('duration_ms')->nullable()->after('completed_at');
        });

        // Backfill existing completed jobs with calculated duration
        DB::table('backup_jobs')
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->orderBy('id')
            ->each(function ($job) {
                $startedAt = Carbon::parse($job->started_at);
                $completedAt = Carbon::parse($job->completed_at);
                $durationMs = (int) $startedAt->diffInMilliseconds($completedAt);

                DB::table('backup_jobs')
                    ->where('id', $job->id)
                    ->update(['duration_ms' => $durationMs]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backup_jobs', function (Blueprint $table) {
            $table->dropColumn('duration_ms');
        });
    }
};
