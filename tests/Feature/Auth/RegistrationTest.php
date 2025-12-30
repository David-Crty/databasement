<?php

use App\Models\DatabaseServer;
use App\Models\User;
use App\Services\DemoBackupService;

// First user registration (allowed)
test('registration screen can be rendered when no users exist', function () {
    $response = $this->get(route('register'));

    $response->assertStatus(200);
});

test('first user can register as admin', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    // First user should be admin
    expect(auth()->user()->role)->toBe(User::ROLE_ADMIN);
});

test('first user can create demo backup during registration', function () {
    // Mock the DemoBackupService to verify it's called
    $mockService = Mockery::mock(DemoBackupService::class);
    $mockService->shouldReceive('createDemoBackup')
        ->once()
        ->andReturn(DatabaseServer::factory()->make());

    $this->app->instance(DemoBackupService::class, $mockService);

    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'create_demo_backup' => '1',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

// Registration closed after first user (blocked)
test('registration screen returns 401 when users exist', function () {
    User::factory()->create();

    $response = $this->get(route('register'));

    $response->assertStatus(401);
});

test('registration POST returns 403 when users exist', function () {
    User::factory()->create();

    $response = $this->post(route('register.store'), [
        'name' => 'Second User',
        'email' => 'second@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertForbidden();
    $this->assertGuest();

    // User should not have been created
    $this->assertDatabaseMissing('users', [
        'email' => 'second@example.com',
    ]);
});

// Invitation flow (allowed for non-first users)
test('users can join via invitation link even when registration is closed', function () {
    // Create first admin
    User::factory()->create(['role' => User::ROLE_ADMIN]);

    // Create invited user (pending invitation)
    $invitedUser = User::factory()->create([
        'password' => null,
        'invitation_token' => 'test-token-123',
        'invitation_accepted_at' => null,
        'role' => User::ROLE_MEMBER,
    ]);

    // Accept invitation page should be accessible even though registration is closed
    $response = $this->get(route('invitation.accept', $invitedUser->invitation_token));
    $response->assertOk();
});
