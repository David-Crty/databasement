<?php

use App\Livewire\ApiToken\Index;
use App\Models\User;
use Livewire\Livewire;

test('can create a new api token and use it to authenticate', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->set('tokenName', 'Test Token')
        ->call('createToken');

    expect($user->tokens()->where('name', 'Test Token')->exists())->toBeTrue();

    // Use the created token to call the API
    $plainTextToken = $component->get('newToken');

    $this->withHeader('Authorization', 'Bearer '.$plainTextToken)
        ->getJson(route('api.database-servers.index'))
        ->assertOk();
});

test('can revoke an existing token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Token to Delete');
    $tokenId = $token->accessToken->id;

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('confirmDelete', $tokenId)
        ->call('deleteToken');

    expect($user->tokens()->where('id', $tokenId)->exists())->toBeFalse();
});

test('regular user cannot revoke another users token', function () {
    $owner = User::factory()->create(['role' => User::ROLE_MEMBER]);
    $otherUser = User::factory()->create(['role' => User::ROLE_MEMBER]);
    $token = $owner->createToken('Owner Token');
    $tokenId = $token->accessToken->id;

    // Non-admin trying to delete another user's token should fail (token not found due to scoping)
    Livewire::actingAs($otherUser)
        ->test(Index::class)
        ->call('confirmDelete', $tokenId)
        ->call('deleteToken');

    // Token should still exist
    expect($owner->tokens()->where('id', $tokenId)->exists())->toBeTrue();
});

test('admin can revoke any users token', function () {
    $owner = User::factory()->create(['role' => User::ROLE_MEMBER]);
    $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    $token = $owner->createToken('Owner Token');
    $tokenId = $token->accessToken->id;

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('confirmDelete', $tokenId)
        ->call('deleteToken');

    expect($owner->tokens()->where('id', $tokenId)->exists())->toBeFalse();
});

test('admin sees all tokens with user info', function () {
    $admin = User::factory()->create(['name' => 'Alice', 'role' => User::ROLE_ADMIN]);
    $member = User::factory()->create(['name' => 'Bob', 'role' => User::ROLE_MEMBER]);
    $admin->createToken('Alice Token');
    $member->createToken('Bob Token');

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee('Alice Token')
        ->assertSee('Bob Token')
        ->assertSee('Alice')
        ->assertSee('Bob')
        ->assertSee('Admin')
        ->assertSee('Member');
});

test('non-admin only sees own tokens', function () {
    $admin = User::factory()->create(['name' => 'Alice', 'role' => User::ROLE_ADMIN]);
    $member = User::factory()->create(['name' => 'Bob', 'role' => User::ROLE_MEMBER]);
    $admin->createToken('Alice Token');
    $member->createToken('Bob Token');

    Livewire::actingAs($member)
        ->test(Index::class)
        ->assertSee('Bob Token')
        ->assertDontSee('Alice Token');
});
