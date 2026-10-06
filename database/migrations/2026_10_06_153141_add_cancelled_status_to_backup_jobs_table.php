<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->allowStatuses(['pending', 'running', 'completed', 'failed', 'cancelled']);
    }

    public function down(): void
    {
        DB::table('backup_jobs')->where('status', 'cancelled')->update(['status' => 'failed']);

        $this->allowStatuses(['pending', 'running', 'completed', 'failed']);
    }

    /**
     * @param  list<string>  $statuses
     */
    private function allowStatuses(array $statuses): void
    {
        // Postgres stores an enum as a check constraint, which change() leaves as it was.
        if (DB::getDriverName() === 'pgsql') {
            $allowed = implode(', ', array_map(fn (string $status) => "'{$status}'", $statuses));

            DB::statement('ALTER TABLE backup_jobs DROP CONSTRAINT IF EXISTS backup_jobs_status_check');
            DB::statement("ALTER TABLE backup_jobs ADD CONSTRAINT backup_jobs_status_check CHECK (status IN ({$allowed}))");

            return;
        }

        Schema::table('backup_jobs', function (Blueprint $table) use ($statuses) {
            $table->enum('status', $statuses)->change();
        });
    }
};
