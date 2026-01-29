<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Fixes existing snapshot records where compression_type was hardcoded to 'gzip'
     * even when zstd compression was actually used. The correct compression type
     * is detected from the filename extension.
     */
    public function up(): void
    {
        // Update snapshots with .zst extension to have compression_type = 'zstd'
        DB::table('snapshots')
            ->where('filename', 'like', '%.zst')
            ->update(['compression_type' => 'zstd']);

        // Update snapshots with .gz extension to have compression_type = 'gzip'
        // (should already be correct, but ensures consistency)
        DB::table('snapshots')
            ->where('filename', 'like', '%.gz')
            ->update(['compression_type' => 'gzip']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse needed - the data was incorrect before
    }
};
