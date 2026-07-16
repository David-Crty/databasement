<?php

use App\Enums\Ability;
use App\Models\Organization;
use App\Models\User;
use App\Support\BouncerScope;
use Illuminate\Support\Facades\Queue;
use Silber\Bouncer\BouncerFacade as Bouncer;

/**
 * Exercises the real /mcp HTTP transport and its middleware stack with a genuine
 * personal access token (not the actingAs()->tool() harness, which bypasses HTTP
 * middleware). This is what proves auth:sanctum, SetCurrentOrganization and
 * ScopeBouncer are wired in the correct order on the route — the harness can't,
 * because it relies on the test's pre-applied org scope.
 */
test('mcp http request scopes Bouncer so org-role abilities resolve in tools', function () {
    Queue::fake();
    $org = Organization::default();

    // A non-super-admin whose run-backups ability comes solely from an org-scoped
    // role assignment — exactly the case that needs ScopeBouncer to resolve.
    $user = User::factory()->create();
    Bouncer::assign('operator')->to($user, $org->id);
    Bouncer::refreshFor($user);

    $server = createDatabaseServer(['database_names' => ['testdb']]);
    $token = $user->createToken('mcp')->plainTextToken;

    // Simulate a fresh production request: nothing has applied a Bouncer scope yet,
    // so the route middleware alone must establish it. (Without this reset, the
    // scope pre-applied by setupOrgContext() would mask a missing ScopeBouncer.)
    BouncerScope::apply(null);

    $response = $this->withToken($token)
        ->withHeaders(['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'trigger-backup-tool',
                'arguments' => ['database_server_id' => $server->id],
            ],
        ]);

    $response->assertOk()
        ->assertSee('Backup started successfully', escape: false)
        ->assertDontSee('Permission denied', escape: false);

    Queue::assertPushed(\App\Jobs\ProcessBackupJob::class);
});

test('mcp http request denies a member lacking the run-backups ability', function () {
    Queue::fake();

    // Necessity proof: holds every ability except run-backups and is still denied.
    $user = User::factory()->withAllAbilitiesExcept(Ability::RunBackups->value)->create();
    $server = createDatabaseServer(['database_names' => ['testdb']]);
    $token = $user->createToken('mcp')->plainTextToken;

    // Simulate a fresh production request — the route middleware alone establishes
    // the scope, exactly as in the allow case above.
    BouncerScope::apply(null);

    $response = $this->withToken($token)
        ->withHeaders(['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'trigger-backup-tool',
                'arguments' => ['database_server_id' => $server->id],
            ],
        ]);

    $response->assertOk()
        ->assertSee('Permission denied', escape: false)
        ->assertDontSee('Backup started successfully', escape: false);

    Queue::assertNothingPushed();
});

test('mcp http request denies access to an organization the user does not belong to', function () {
    Queue::fake();

    // Member of the default org only; the request targets a different org.
    $user = User::factory()->create();
    $otherOrg = Organization::factory()->create(['name' => 'Other Org']);
    $server = createDatabaseServer(['database_names' => ['testdb']]);
    $token = $user->createToken('mcp')->plainTextToken;

    BouncerScope::apply(null);

    $response = $this->withToken($token)
        ->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'X-Organization-Id' => $otherOrg->id,
        ])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'trigger-backup-tool',
                'arguments' => ['database_server_id' => $server->id],
            ],
        ]);

    // SetCurrentOrganization aborts 403 before the tool ever runs.
    $response->assertForbidden();
    Queue::assertNothingPushed();
});

test('mcp http request rejects an invalid bearer token', function () {
    Queue::fake();
    $server = createDatabaseServer(['database_names' => ['testdb']]);

    $response = $this->withToken('not-a-real-token')
        ->withHeaders(['Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'trigger-backup-tool',
                'arguments' => ['database_server_id' => $server->id],
            ],
        ]);

    // auth:sanctum rejects before any org resolution or tool execution.
    $response->assertUnauthorized();
    Queue::assertNothingPushed();
});
