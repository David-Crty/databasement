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
        Schema::table('volumes', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['organization_id', 'name']);
        });
    }

    /**
     * Reverse the migrations. Fails while two organizations hold a volume of
     * the same name.
     *
     * MySQL dropped the organization_id foreign key's own index once the
     * unique index could serve it, so one is created before that goes.
     */
    public function down(): void
    {
        Schema::table('volumes', function (Blueprint $table) {
            $table->index('organization_id');
            $table->dropUnique(['organization_id', 'name']);
            $table->unique(['name']);
        });
    }
};
