<?php

use App\Livewire\ApiToken\Index;
use App\Models\User;
use Livewire\Livewire;

test('guests cannot access api tokens page', function () {
    $this->get(route('api-tokens.index'))
        ->assertRedirect(route('login'));
});

test('authenticated users can access api tokens page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('api-tokens.index'))
        ->assertOk()
        ->assertSeeLivewire(Index::class);
});

test('can create a new api token and use it to authenticate', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->set('tokenName', 'Test Token')
        ->call('createToken')
        ->assertSet('showCreateModal', false)
        ->assertSet('showTokenModal', true)
        ->assertNotSet('newToken', null);

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
        ->assertSet('showDeleteModal', true)
        ->assertSet('deleteTokenId', $tokenId)
        ->call('deleteToken')
        ->assertSet('showDeleteModal', false);

    expect($user->tokens()->where('id', $tokenId)->exists())->toBeFalse();
});

test('regular user cannot revoke another users token', function () {
    $owner = User::factory()->create(['role' => User::ROLE_MEMBER]);
    $otherUser = User::factory()->create(['role' => User::ROLE_MEMBER]);
    $token = $owner->createToken('Owner Token');
    $tokenId = $token->accessToken->id;

    Livewire::actingAs($otherUser)
        ->test(Index::class)
        ->call('confirmDelete', $tokenId)
        ->call('deleteToken')
        ->assertSet('showDeleteModal', false);

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
        ->call('deleteToken')
        ->assertSet('showDeleteModal', false);

    // Token should be deleted
    expect($owner->tokens()->where('id', $tokenId)->exists())->toBeFalse();
});

test('index shows all tokens with user info', function () {
    $user1 = User::factory()->create(['name' => 'Alice', 'role' => User::ROLE_ADMIN]);
    $user2 = User::factory()->create(['name' => 'Bob', 'role' => User::ROLE_MEMBER]);
    $user1->createToken('Alice Token');
    $user2->createToken('Bob Token');

    Livewire::actingAs($user1)
        ->test(Index::class)
        ->assertSee('Alice Token')
        ->assertSee('Bob Token')
        ->assertSee('Alice')
        ->assertSee('Bob')
        ->assertSee('Admin')
        ->assertSee('Member');
});
