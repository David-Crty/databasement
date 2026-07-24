<?php

use App\Models\Organization;
use App\Models\User;

test('returns the authenticated user organizations with roles', function () {
    $user = User::factory()->create();
    $otherOrg = Organization::factory()->create(['name' => 'Second Org']);
    attachUserToOrg($user, $otherOrg, 'admin');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/user/organizations');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.role', 'viewer')
        ->assertJsonPath('data.1.name', 'Second Org')
        ->assertJsonPath('data.1.role', 'admin');
});

test('requires authentication', function () {
    $this->getJson('/api/v1/user/organizations')->assertUnauthorized();
});
