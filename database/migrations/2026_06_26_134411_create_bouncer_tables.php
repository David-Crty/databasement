<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Silber\Bouncer\Database\Models;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // ULID adaptation for Databasement:
        // - The `User` model uses an auto-increment BIGINT key, so the entity
        //   morphs that point at users/roles (assigned_roles.entity_id,
        //   permissions.entity_id) stay BIGINT — they already match.
        // - Role and ability *definitions* are global; only role *assignments*
        //   are scoped to the tenant (`Organization`, HasUlids). The `scope`
        //   column is therefore a string(26): it carries the org id on
        //   assigned_roles, and stays null on the global roles/abilities rows.
        // - The object-level morphs (abilities.entity_id,
        //   assigned_roles.restricted_to_id) point at ULID domain models and
        //   are widened to string(26) for the (deferred) resource-scoped
        //   abilities extension point. They are nullable/unused in v1.
        Schema::create(Models::table('abilities'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('entity_id', 26)->nullable();
            $table->string('entity_type')->nullable();
            $table->boolean('only_owned')->default(false);
            $table->json('options')->nullable();
            $table->string('scope', 26)->nullable()->index();
            $table->timestamps();

            $table->index(['entity_id', 'entity_type']);
        });

        Schema::create(Models::table('roles'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('title')->nullable();
            // Marks the seeded built-in roles (admin/member/operator/viewer +
            // the sandbox demo role). Built-ins are protected from deletion and
            // renaming; this is the runtime source of truth now that the
            // App\Enums\UserRole enum no longer exists.
            $table->boolean('built_in')->default(false);
            // Kept for Bouncer's schema, but always null: role definitions are
            // global, so names are unique across the whole application.
            $table->string('scope', 26)->nullable()->index();
            $table->timestamps();

            $table->unique('name', 'roles_name_unique');
        });

        Schema::create(Models::table('assigned_roles'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('role_id')->unsigned()->index();
            $table->bigInteger('entity_id')->unsigned();
            $table->string('entity_type');
            $table->string('restricted_to_id', 26)->nullable();
            $table->string('restricted_to_type')->nullable();
            $table->string('scope', 26)->nullable()->index();

            $table->index(
                ['entity_id', 'entity_type', 'scope'],
                'assigned_roles_entity_index'
            );

            $table->foreign('role_id')
                ->references('id')->on(Models::table('roles'))
                ->onUpdate('cascade')->onDelete('cascade');
        });

        Schema::create(Models::table('permissions'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('ability_id')->unsigned()->index();
            $table->bigInteger('entity_id')->unsigned()->nullable();
            $table->string('entity_type')->nullable();
            $table->boolean('forbidden')->default(false);
            $table->string('scope', 26)->nullable()->index();

            $table->index(
                ['entity_id', 'entity_type', 'scope'],
                'permissions_entity_index'
            );

            $table->foreign('ability_id')
                ->references('id')->on(Models::table('abilities'))
                ->onUpdate('cascade')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop(Models::table('permissions'));
        Schema::drop(Models::table('assigned_roles'));
        Schema::drop(Models::table('roles'));
        Schema::drop(Models::table('abilities'));
    }
};
