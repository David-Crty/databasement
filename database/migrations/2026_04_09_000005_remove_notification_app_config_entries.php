<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('app_configs')
            ->where('id', 'like', 'notifications.%')
            ->delete();
    }

    public function down(): void
    {
        // Data migration — no automatic rollback
    }
};
