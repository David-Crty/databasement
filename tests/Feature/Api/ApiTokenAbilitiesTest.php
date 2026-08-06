<?php

use App\Enums\Ability;
use App\Jobs\ProcessBackupJob;
use App\Models\User;
use App\Support\BouncerScope;
use Illuminate\Support\Facades\Queue;

/**
 * Exercises the Gate::before combination in
 * AppServiceProvider::registerBouncer() that layers Sanctum token abilities on
 * top of Bouncer's role/direct-ability resolution, via real HTTP requests with
 * a genuine personal access token (matching ApiHttpAuthorizationTest's style).
 */
test('a token scoped to the required ability can call the gated endpoint', function () {
    Queue::fake();

    $user = User::factory()->withAbilities([Ability::RunBackups->value])->create();
    $server = createDatabaseServer(['database_names' => ['testdb']]);
    $token = $user->createToken('scoped', [Ability::RunBackups->value])->plainTextToken;

    BouncerScope::apply(null);

    $this->withToken($token)
        ->postJson("/api/v1/database-servers/{$server->id}/backup")
        ->assertStatus(202);

    Queue::assertPushed(ProcessBackupJob::class);
});

test('a token not scoped to the required ability is denied even though the user holds it via Bouncer', function () {
    Queue::fake();

    $user = User::factory()->withAbilities([Ability::RunBackups->value])->create();
    $server = createDatabaseServer(['database_names' => ['testdb']]);
    // The token only covers an unrelated ability — the user's Bouncer grant for
    // run-backups must not leak through.
    $token = $user->createToken('scoped', [Ability::DownloadSnapshots->value])->plainTextToken;

    BouncerScope::apply(null);

    $this->withToken($token)
        ->postJson("/api/v1/database-servers/{$server->id}/backup")
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('a full-access token still defers to Bouncer as before', function () {
    Queue::fake();

    $user = User::factory()->withAbilities([Ability::RunBackups->value])->create();
    $server = createDatabaseServer(['database_names' => ['testdb']]);
    $token = $user->createToken('legacy')->plainTextToken;

    BouncerScope::apply(null);

    $this->withToken($token)
        ->postJson("/api/v1/database-servers/{$server->id}/backup")
        ->assertStatus(202);

    Queue::assertPushed(ProcessBackupJob::class);
});

test('a super admin token bypasses ability scoping for the catalogue', function () {
    Queue::fake();

    $superAdmin = User::factory()->superAdmin()->create();
    $server = createDatabaseServer(['database_names' => ['testdb']]);
    // Scoped to an unrelated ability — the super-admin bypass still applies.
    $token = $superAdmin->createToken('scoped', [Ability::DownloadSnapshots->value])->plainTextToken;

    BouncerScope::apply(null);

    $this->withToken($token)
        ->postJson("/api/v1/database-servers/{$server->id}/backup")
        ->assertStatus(202);

    Queue::assertPushed(ProcessBackupJob::class);
});

test('session auth is unaffected by token scoping', function () {
    Queue::fake();

    $user = User::factory()->withAbilities([Ability::RunBackups->value])->create();
    $server = createDatabaseServer(['database_names' => ['testdb']]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/database-servers/{$server->id}/backup")
        ->assertStatus(202);

    Queue::assertPushed(ProcessBackupJob::class);
});
