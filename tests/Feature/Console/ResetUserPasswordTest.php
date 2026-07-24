<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('resets the password for the given user', function () {
    $user = User::factory()->create(['email' => 'jane@example.com']);

    $this->artisan('user:reset-password', ['email' => 'jane@example.com'])
        ->expectsQuestion('Enter the new password', 'new-secure-password')
        ->expectsOutput('Password reset for jane@example.com.')
        ->assertExitCode(0);

    expect(Hash::check('new-secure-password', $user->refresh()->password))->toBeTrue();
});

test('fails when no user matches the email', function () {
    $this->artisan('user:reset-password', ['email' => 'missing@example.com'])
        ->expectsOutput('No user found with email [missing@example.com].')
        ->assertExitCode(1);
});
