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

    // First user should be super admin
    $user = auth()->user();
    expect($user->super_admin)->toBeTrue()
        ->and($user->roleNameIn(\App\Models\Organization::default()))->toBe('admin');
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

test('registration screen renders validation errors inline for each field', function () {
    // Guards the Mary `error-field` wiring: without it the plain-POST form
    // silently swallows validation errors (issue #481).
    $response = $this->from(route('register'))
        ->followingRedirects()
        ->post(route('register.store'), [
            'name' => 'Jane Doe',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ]);

    $response->assertOk()
        // Each field's message is rendered inline (Mary emits `text-error` divs).
        ->assertSee('The email field must be a valid email address.')
        ->assertSee('The password field must be at least 8 characters.')
        ->assertSee('The password field confirmation does not match.')
        // Non-secret fields keep their old input; passwords are never repopulated.
        ->assertSee('value="Jane Doe"', escape: false)
        ->assertSee('value="not-an-email"', escape: false)
        ->assertDontSee('value="short"', escape: false);

    $this->assertGuest();
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
    User::factory()->create(['role' => 'admin']);

    // Create invited user (pending invitation)
    $invitedUser = User::factory()->create([
        'password' => null,
        'invitation_token' => 'test-token-123',
        'invitation_accepted_at' => null,
        'role' => 'member',
    ]);

    // Accept invitation page should be accessible even though registration is closed
    $response = $this->get(route('invitation.accept', $invitedUser->invitation_token));
    $response->assertOk();
});
