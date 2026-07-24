<?php

use App\Enums\Ability;
use App\Livewire\DatabaseServer\Create as DatabaseServerCreate;
use App\Livewire\DatabaseServer\Edit as DatabaseServerEdit;
use App\Livewire\DatabaseServer\Index as DatabaseServerIndex;
use App\Livewire\Snapshot\Index as SnapshotIndex;
use App\Livewire\Volume\Create as VolumeCreate;
use App\Livewire\Volume\Edit as VolumeEdit;
use App\Livewire\Volume\Index as VolumeIndex;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

beforeEach(function () {
    $this->demoUser = User::factory()->create([
        'email' => User::DEMO_EMAIL,
        'role' => 'viewer',
    ]);
    config(['app.demo_mode' => true]);
});

// DatabaseServer restrictions
test('demo user can view create database server page but cannot save', function () {
    // Demo user can view the create page
    $this->actingAs($this->demoUser)
        ->get(route('database-servers.create'))
        ->assertOk();

    // But attempting to save redirects with a demo notice
    Livewire::actingAs($this->demoUser)
        ->test(DatabaseServerCreate::class)
        ->set('form.name', 'Test Server')
        ->set('form.host', 'localhost')
        ->set('form.port', 3306)
        ->set('form.database_type', 'mysql')
        ->set('form.username', 'root')
        ->set('form.password', 'password')
        ->set('form.backups.0.database_names', ['testdb'])
        ->call('save')
        ->assertRedirect(route('database-servers.index'))
        ->assertSessionHas('demo_notice');
});

test('demo user can view edit database server page but cannot save', function () {
    $server = DatabaseServer::factory()->create();

    // Demo user can view the edit page
    $this->actingAs($this->demoUser)
        ->get(route('database-servers.edit', $server))
        ->assertOk();

    // But attempting to save redirects with a demo notice
    Livewire::actingAs($this->demoUser)
        ->test(DatabaseServerEdit::class, ['server' => $server])
        ->set('form.name', 'Updated Name')
        ->call('save')
        ->assertRedirect(route('database-servers.index'))
        ->assertSessionHas('demo_notice');
});

test('demo user cannot delete database server', function () {
    $server = DatabaseServer::factory()->create();

    Livewire::actingAs($this->demoUser)
        ->test(DatabaseServerIndex::class)
        ->call('confirmDelete', $server->id)
        ->assertForbidden();
});

// Volume restrictions
test('demo user can view create volume page but cannot save', function () {
    $this->actingAs($this->demoUser)
        ->get(route('volumes.create'))
        ->assertOk();

    Livewire::actingAs($this->demoUser)
        ->test(VolumeCreate::class)
        ->set('form.name', 'Test Volume')
        ->set('form.type', 'local')
        ->set('form.path', '/tmp/backups')
        ->call('save')
        ->assertRedirect(route('volumes.index'))
        ->assertSessionHas('demo_notice');
});

test('demo user can view edit volume page but cannot save', function () {
    $volume = Volume::factory()->create();

    // Demo user can view the edit page
    $this->actingAs($this->demoUser)
        ->get(route('volumes.edit', $volume))
        ->assertOk();

    // But attempting to save redirects with a demo notice
    Livewire::actingAs($this->demoUser)
        ->test(VolumeEdit::class, ['volume' => $volume])
        ->set('form.name', 'Updated Volume')
        ->call('save')
        ->assertRedirect(route('volumes.index'))
        ->assertSessionHas('demo_notice');
});

test('demo user cannot delete volume', function () {
    $volume = Volume::factory()->create();

    Livewire::actingAs($this->demoUser)
        ->test(VolumeIndex::class)
        ->call('confirmDelete', $volume->id)
        ->assertForbidden();
});

// Snapshot restrictions
test('demo user cannot delete snapshot', function () {
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $factory = app(BackupJobFactory::class);
    $snapshot = $factory->createSnapshots($server->backups->first(), 'manual')[0];

    Livewire::actingAs($this->demoUser)
        ->test(SnapshotIndex::class)
        ->call('confirmDeleteSnapshot', $snapshot->id)
        ->assertForbidden();
});

// Demo user CAN do these things
test('demo user can view database servers', function () {
    DatabaseServer::factory()->create(['name' => 'Test Server']);

    Livewire::actingAs($this->demoUser)
        ->test(DatabaseServerIndex::class)
        ->assertSee('Test Server');
});

test('demo user can view volumes', function () {
    Volume::factory()->create(['name' => 'Test Volume']);

    Livewire::actingAs($this->demoUser)
        ->test(VolumeIndex::class)
        ->assertSee('Test Volume');
});

test('demo user can trigger backup', function () {
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);

    // Just verify the authorization passes (not the actual backup execution)
    expect($this->demoUser->can('backup', $server))->toBeTrue();
});

test('demo user can trigger restore', function () {
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);

    expect($this->demoUser->can('restore', $server))->toBeTrue();
});

test('demo user gets adminer via the isDemo bypass, not a role ability', function () {
    // The demo user is a plain viewer that lacks the use-adminer ability...
    expect($this->demoUser->can(Ability::UseAdminer->value))->toBeFalse()
        // ...yet DatabaseServerPolicy@adminer grants access through the isDemo() bypass.
        ->and($this->demoUser->can('adminer', DatabaseServer::class))->toBeTrue();

    // Proof the grant is demo-specific: an equally ability-less non-demo viewer is denied.
    $viewer = User::factory()->create();
    expect($viewer->isDemo())->toBeFalse()
        ->and($viewer->can('adminer', DatabaseServer::class))->toBeFalse();
});

test('demo user can manage scheduled restores', function () {
    [$source, $target] = createRestoreServerPair('mysql');
    $scheduledRestore = createScheduledRestore(['source' => $source, 'target' => $target]);

    expect($this->demoUser->can('create', \App\Models\ScheduledRestore::class))->toBeTrue()
        ->and($this->demoUser->can('update', $scheduledRestore))->toBeTrue()
        ->and($this->demoUser->can('delete', $scheduledRestore))->toBeTrue()
        ->and($this->demoUser->can('run', $scheduledRestore))->toBeTrue();
});

// Settings restrictions for demo users
test('demo user cannot access profile settings', function () {
    $this->actingAs($this->demoUser)
        ->get(route('profile.edit'))
        ->assertForbidden();
});

test('demo user cannot access password settings', function () {
    $this->actingAs($this->demoUser)
        ->get(route('user-password.edit'))
        ->assertForbidden();
});

test('demo user cannot access two-factor settings', function () {
    $response = $this->actingAs($this->demoUser)
        ->get(route('two-factor.show'));

    $response->assertForbidden();
});

test('demo user can access api tokens settings', function () {
    $this->actingAs($this->demoUser)
        ->get(route('api-tokens.index'))
        ->assertOk();
});

test('demo user can access preferences settings', function () {
    $this->actingAs($this->demoUser)
        ->get(route('preferences.edit'))
        ->assertOk();
});

// First admin registration in demo mode
test('first admin can register even when demo mode is enabled', function () {
    // Delete all users to simulate fresh install
    User::query()->delete();

    // Access home page with no users - should redirect to register
    $response = $this->get(route('home'));
    $response->assertRedirect(route('register'));

    // Should still be a guest
    $this->assertGuest();

    // Register page should be accessible
    $this->get(route('register'))->assertOk();

    // Complete registration as first admin
    $response = $this->post(route('register.store'), [
        'name' => 'First Admin',
        'email' => 'admin@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    // Should redirect to dashboard after successful registration
    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    // User should be authenticated as the new admin (not demo user)
    $this->assertAuthenticated();
    expect(auth()->user()->email)->toBe('admin@example.com')
        ->and(auth()->user()->roleNameIn(\App\Models\Organization::default()))->toBe('admin')
        ->and(auth()->user()->isDemo())->toBeFalse();

    // Should be able to access dashboard
    $this->get(route('dashboard'))->assertOk();
});
// Demo user creation
test('demo user is created when visiting login page in demo mode', function () {
    // Delete all users
    User::query()->delete();

    // Create an admin so registration is closed
    User::factory()->create(['role' => 'admin']);

    config(['app.demo_mode' => true]);

    // Demo user should not exist yet
    $this->assertDatabaseMissing('users', ['email' => User::DEMO_EMAIL]);

    // Visit login page
    $this->get(route('login'))->assertOk();

    // Demo user should now exist with the viewer role and be recognized as demo
    $this->assertDatabaseHas('users', ['email' => User::DEMO_EMAIL]);
    $demoUser = User::where('email', User::DEMO_EMAIL)->first();
    expect($demoUser->roleNameIn(\App\Models\Organization::default()))->toBe('viewer')
        ->and($demoUser->isDemo())->toBeTrue();
});
