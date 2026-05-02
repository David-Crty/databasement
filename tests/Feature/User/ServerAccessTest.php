<?php

use App\Livewire\BackupJob\Index as BackupJobIndex;
use App\Livewire\DatabaseServer\Index as DatabaseServerIndex;
use App\Livewire\User\ServerAccess;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Models\UserServerAccess;
use Livewire\Livewire;

// ── Scoped visibility ────────────────────────────────────────────────────────

test('viewer without grants sees all servers', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create(['name' => 'Production DB']);

    Livewire::actingAs($viewer)
        ->test(DatabaseServerIndex::class)
        ->assertSee('Production DB');
});

test('viewer with grants only sees granted servers', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $granted = DatabaseServer::factory()->create(['name' => 'Client DB']);
    $hidden = DatabaseServer::factory()->create(['name' => 'Other Client DB']);

    UserServerAccess::factory()->create([
        'user_id' => $viewer->id,
        'database_server_id' => $granted->id,
    ]);

    Livewire::actingAs($viewer)
        ->test(DatabaseServerIndex::class)
        ->assertSee('Client DB')
        ->assertDontSee('Other Client DB');
});

test('member sees all servers regardless of grants', function () {
    $member = User::factory()->create(['role' => 'member']);
    $server = DatabaseServer::factory()->create(['name' => 'All Servers DB']);

    // Grant to a different server only — member should still see both
    UserServerAccess::factory()->create([
        'user_id' => $member->id,
        'database_server_id' => DatabaseServer::factory()->create()->id,
    ]);

    Livewire::actingAs($member)
        ->test(DatabaseServerIndex::class)
        ->assertSee('All Servers DB');
});

test('admin sees all servers regardless of grants', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    DatabaseServer::factory()->create(['name' => 'Admin Visible DB']);

    Livewire::actingAs($admin)
        ->test(DatabaseServerIndex::class)
        ->assertSee('Admin Visible DB');
});

// ── Policy: DatabaseServerPolicy ────────────────────────────────────────────

test('scoped viewer cannot view server they have no grant for', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create();

    // Grant access to a different server so viewer becomes scoped
    UserServerAccess::factory()->create([
        'user_id' => $viewer->id,
        'database_server_id' => DatabaseServer::factory()->create()->id,
    ]);

    expect($viewer->isScopedUser())->toBeTrue();
    expect($viewer->can('view', $server))->toBeFalse();
});

test('scoped viewer can view server they have a grant for', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create();

    UserServerAccess::factory()->create([
        'user_id' => $viewer->id,
        'database_server_id' => $server->id,
    ]);

    expect($viewer->can('view', $server))->toBeTrue();
});

test('scoped viewer without can_restore cannot restore', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create();

    UserServerAccess::factory()->create([
        'user_id' => $viewer->id,
        'database_server_id' => $server->id,
        'can_restore' => false,
    ]);

    expect($viewer->can('restore', $server))->toBeFalse();
});

test('scoped viewer with can_restore can restore', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create();

    UserServerAccess::factory()->create([
        'user_id' => $viewer->id,
        'database_server_id' => $server->id,
        'can_restore' => true,
    ]);

    expect($viewer->can('restore', $server))->toBeTrue();
});

// ── Policy: SnapshotPolicy ───────────────────────────────────────────────────

test('scoped viewer cannot download snapshot from ungranted server', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create();

    // Make viewer scoped by granting a different server
    UserServerAccess::factory()->create([
        'user_id' => $viewer->id,
        'database_server_id' => DatabaseServer::factory()->create()->id,
        'can_download' => true,
    ]);

    $snapshot = \App\Models\Snapshot::factory()->forServer($server)->create();

    expect($viewer->can('download', $snapshot))->toBeFalse();
});

test('scoped viewer can download snapshot when grant allows all databases', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create();

    UserServerAccess::factory()->create([
        'user_id' => $viewer->id,
        'database_server_id' => $server->id,
        'allowed_databases' => null,
        'can_download' => true,
    ]);

    $snapshot = \App\Models\Snapshot::factory()->forServer($server)->create(['database_name' => 'any_db']);

    expect($viewer->can('download', $snapshot))->toBeTrue();
});

test('scoped viewer cannot download snapshot for unallowed database', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create();

    UserServerAccess::factory()->create([
        'user_id' => $viewer->id,
        'database_server_id' => $server->id,
        'allowed_databases' => ['allowed_db'],
        'can_download' => true,
    ]);

    $snapshot = \App\Models\Snapshot::factory()->forServer($server)->create(['database_name' => 'other_db']);

    expect($viewer->can('download', $snapshot))->toBeFalse();
});

test('scoped viewer can download snapshot for allowed database', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create();

    UserServerAccess::factory()->create([
        'user_id' => $viewer->id,
        'database_server_id' => $server->id,
        'allowed_databases' => ['client_db'],
        'can_download' => true,
    ]);

    $snapshot = \App\Models\Snapshot::factory()->forServer($server)->create(['database_name' => 'client_db']);

    expect($viewer->can('download', $snapshot))->toBeTrue();
});

// ── Admin UI: ServerAccess component ────────────────────────────────────────

test('admin can grant server access to a viewer', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $viewer = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create();

    Livewire::actingAs($admin)
        ->test(ServerAccess::class, ['user' => $viewer])
        ->call('openGrantModal')
        ->set('selectedServerId', $server->id)
        ->set('canDownload', true)
        ->set('canRestore', false)
        ->call('grantAccess');

    expect(UserServerAccess::where('user_id', $viewer->id)
        ->where('database_server_id', $server->id)
        ->exists()
    )->toBeTrue();
});

test('admin can revoke server access', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $viewer = User::factory()->create(['role' => 'viewer']);
    $access = UserServerAccess::factory()->create(['user_id' => $viewer->id]);

    Livewire::actingAs($admin)
        ->test(ServerAccess::class, ['user' => $viewer])
        ->call('revokeAccess', $access->id);

    expect(UserServerAccess::find($access->id))->toBeNull();
});

test('non-admin cannot manage server access grants', function () {
    $member = User::factory()->create(['role' => 'member']);
    $viewer = User::factory()->create(['role' => 'viewer']);

    Livewire::actingAs($member)
        ->test(ServerAccess::class, ['user' => $viewer])
        ->call('openGrantModal')
        ->assertForbidden();
});

test('granting access with specific databases restricts to those databases', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $viewer = User::factory()->create(['role' => 'viewer']);
    $server = DatabaseServer::factory()->create();

    Livewire::actingAs($admin)
        ->test(ServerAccess::class, ['user' => $viewer])
        ->call('openGrantModal')
        ->set('selectedServerId', $server->id)
        ->set('allowedDatabases', ['client_db', 'shop_db'])
        ->call('grantAccess');

    $access = UserServerAccess::where('user_id', $viewer->id)
        ->where('database_server_id', $server->id)
        ->first();

    expect($access)->not->toBeNull();
    expect($access->allowed_databases)->toBe(['client_db', 'shop_db']);
});

// ── BackupJob scoping ────────────────────────────────────────────────────────

test('scoped viewer only sees backup jobs for their granted servers', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $grantedServer = DatabaseServer::factory()->create(['name' => 'Granted Server']);
    $otherServer = DatabaseServer::factory()->create(['name' => 'Hidden Server']);

    UserServerAccess::factory()->create([
        'user_id' => $viewer->id,
        'database_server_id' => $grantedServer->id,
    ]);

    \App\Models\Snapshot::factory()->forServer($grantedServer)->create();
    \App\Models\Snapshot::factory()->forServer($otherServer)->create();

    Livewire::actingAs($viewer)
        ->test(BackupJobIndex::class)
        ->assertSee('Granted Server')
        ->assertDontSee('Hidden Server');
});
