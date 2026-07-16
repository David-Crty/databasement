<?php

use App\Enums\Ability;
use App\Jobs\ProcessBackupJob;
use App\Models\Organization;
use App\Models\User;
use App\Support\BouncerScope;
use Illuminate\Support\Facades\Queue;
use Silber\Bouncer\BouncerFacade as Bouncer;

/**
 * Exercises the real API HTTP stack with a genuine personal access token (the
 * Authorization header), not actingAs(). This drives auth:sanctum,
 * SetCurrentOrganization and ScopeBouncer in their real order — the same backup
 * ability boundary the MCP transport is covered for in McpHttpAuthorizationTest.
 */
test('api request scopes Bouncer so org-role abilities resolve', function () {
    Queue::fake();
    $org = Organization::default();

    // A non-super-admin whose run-backups ability comes solely from an org-scoped
    // role assignment — exactly the case that needs ScopeBouncer to resolve.
    $user = User::factory()->create();
    Bouncer::assign('operator')->to($user, $org->id);
    Bouncer::refreshFor($user);

    $server = createDatabaseServer(['database_names' => ['testdb']]);
    $token = $user->createToken('api')->plainTextToken;

    // Simulate a fresh production request: the route middleware alone establishes
    // the scope. (Without this reset, setupOrgContext()'s scope would mask it.)
    BouncerScope::apply(null);

    $response = $this->withToken($token)
        ->postJson("/api/v1/database-servers/{$server->id}/backup");

    $response->assertStatus(202)
        ->assertJsonPath('message', 'Backup started successfully!');

    Queue::assertPushed(ProcessBackupJob::class);
});

test('api request denies a member lacking the run-backups ability', function () {
    Queue::fake();

    // Holds every catalogue ability except run-backups, proving the backup
    // boundary is gated on precisely that ability — not an incidental empty set.
    $user = User::factory()->withAllAbilitiesExcept(Ability::RunBackups->value)->create();
    $server = createDatabaseServer(['database_names' => ['testdb']]);
    $token = $user->createToken('api')->plainTextToken;

    BouncerScope::apply(null);

    $response = $this->withToken($token)
        ->postJson("/api/v1/database-servers/{$server->id}/backup");

    $response->assertForbidden();
    Queue::assertNothingPushed();
});

test('api request denies access to an organization the user does not belong to', function () {
    Queue::fake();

    // Member of the default org only; the request targets a different org.
    $user = User::factory()->create();
    $otherOrg = Organization::factory()->create(['name' => 'Other Org']);
    $server = createDatabaseServer(['database_names' => ['testdb']]);
    $token = $user->createToken('api')->plainTextToken;

    BouncerScope::apply(null);

    $response = $this->withToken($token)
        ->withHeaders(['X-Organization-Id' => $otherOrg->id])
        ->postJson("/api/v1/database-servers/{$server->id}/backup");

    // SetCurrentOrganization aborts 403 before the controller runs.
    $response->assertForbidden();
    Queue::assertNothingPushed();
});

test('api request rejects an invalid bearer token', function () {
    Queue::fake();
    $server = createDatabaseServer(['database_names' => ['testdb']]);

    $response = $this->withToken('not-a-real-token')
        ->postJson("/api/v1/database-servers/{$server->id}/backup");

    // auth:sanctum rejects before any org resolution or controller execution.
    $response->assertUnauthorized();
    Queue::assertNothingPushed();
});
