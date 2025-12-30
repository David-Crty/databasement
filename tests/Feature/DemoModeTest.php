<?php

use App\Livewire\BackupJob\Index as BackupJobIndex;
use App\Livewire\DatabaseServer\Create as DatabaseServerCreate;
use App\Livewire\DatabaseServer\Edit as DatabaseServerEdit;
use App\Livewire\DatabaseServer\Index as DatabaseServerIndex;
use App\Livewire\Volume\Create as VolumeCreate;
use App\Livewire\Volume\Edit as VolumeEdit;
use App\Livewire\Volume\Index as VolumeIndex;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

beforeEach(function () {
    $this->demoUser = User::factory()->create(['role' => User::ROLE_DEMO]);
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
        ->set('form.database_names', ['testdb'])
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
    // Demo user can view the create page
    $this->actingAs($this->demoUser)
        ->get(route('volumes.create'))
        ->assertOk();

    // But attempting to save redirects with a demo notice
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
        ->set('form.name', 'Updated Name')
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
    $snapshot = $factory->createSnapshots($server, 'manual')[0];

    Livewire::actingAs($this->demoUser)
        ->test(BackupJobIndex::class)
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

test('demo user cannot access api tokens settings', function () {
    $this->actingAs($this->demoUser)
        ->get(route('api-tokens.index'))
        ->assertForbidden();
});

test('demo user can access appearance settings', function () {
    $this->actingAs($this->demoUser)
        ->get(route('appearance.edit'))
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
        ->and(auth()->user()->role)->toBe(User::ROLE_ADMIN)
        ->and(auth()->user()->isDemo())->toBeFalse();

    // Should be able to access dashboard
    $this->get(route('dashboard'))->assertOk();
});
// Demo user creation
test('demo user is created when visiting login page in demo mode', function () {
    // Delete all users
    User::query()->delete();

    // Create an admin so registration is closed
    User::factory()->create(['role' => User::ROLE_ADMIN]);

    config([
        'app.demo_mode' => true,
        'app.demo_user_email' => 'auto-demo@example.com',
        'app.demo_user_password' => 'demopass',
    ]);

    // Demo user should not exist yet
    $this->assertDatabaseMissing('users', ['email' => 'auto-demo@example.com']);

    // Visit login page
    $this->get(route('login'))->assertOk();

    // Demo user should now exist
    $this->assertDatabaseHas('users', [
        'email' => 'auto-demo@example.com',
        'role' => User::ROLE_DEMO,
    ]);
});
