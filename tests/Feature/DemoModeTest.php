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

test('visiting home page auto-logs in as demo user and redirects to dashboard', function () {
    // Delete the demo user created in beforeEach so we start fresh
    User::query()->delete();

    // Create an existing user so we don't get redirected to register
    User::factory()->create(['role' => User::ROLE_ADMIN]);

    config(['app.demo_user_email' => 'test-demo@example.com']);

    // Visit home page - should auto-login as demo user and redirect to dashboard
    $response = $this->get('/');
    $response->assertRedirect(route('dashboard'));

    // Demo user should have been created and logged in
    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'email' => 'test-demo@example.com',
        'role' => User::ROLE_DEMO,
    ]);

    // Following the redirect should show the dashboard
    $this->get(route('dashboard'))->assertOk();

    // Verify we're logged in as the demo user
    expect(auth()->user()->email)->toBe('test-demo@example.com')
        ->and(auth()->user()->isDemo())->toBeTrue();
});

test('demo mode middleware does not interfere when disabled', function () {
    // Disable demo mode (overrides beforeEach)
    config(['app.demo_mode' => false]);

    // Logout any user (beforeEach creates a demo user)
    auth()->logout();

    $response = $this->get(route('dashboard'));

    // Should redirect to login since demo mode is disabled and no user logged in
    $response->assertRedirect(route('login'));
});

test('real user can login and stays logged in after navigating when demo mode is enabled', function () {
    config(['app.demo_user_email' => 'demo@example.com']);

    // Create a real user
    $realUser = User::factory()->create([
        'email' => 'realuser@example.com',
        'password' => bcrypt('password123'),
        'role' => User::ROLE_ADMIN,
        'two_factor_secret' => null,
        'two_factor_confirmed_at' => null,
    ]);

    // Login as the real user
    $this->post(route('login.store'), [
        'email' => 'realuser@example.com',
        'password' => 'password123',
    ]);

    // Now navigate to dashboard - should still be the real user
    $response = $this->get(route('dashboard'));
    $response->assertOk();

    // Should still be logged in as the real user, NOT replaced by demo user
    expect(auth()->user()->email)->toBe('realuser@example.com')
        ->and(auth()->user()->isDemo())->toBeFalse();

    // Navigate to another page
    $response = $this->get(route('database-servers.index'));
    $response->assertOk();

    // Should still be the real user
    expect(auth()->user()->email)->toBe('realuser@example.com')
        ->and(auth()->user()->isDemo())->toBeFalse();
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
    // Two-factor route may have password.confirm middleware
    // Demo users should not be able to access it (either 403 or redirect to confirm)
    $response = $this->actingAs($this->demoUser)
        ->get(route('two-factor.show'));

    // Should either be forbidden or redirected (not OK)
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

    // Access home page with no users - should redirect to register, not create demo user
    $response = $this->get(route('home'));
    $response->assertRedirect(route('register'));

    // No demo user should have been created yet
    $this->assertDatabaseMissing('users', [
        'email' => 'demo@example.com',
    ]);

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
