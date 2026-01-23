<?php

use App\Livewire\Settings\ApiTokens;
use App\Models\User;
use Livewire\Livewire;

test('can create a new api token', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->set('tokenName', 'Test Token')
        ->call('createToken')
        ->assertSet('showCreateModal', false)
        ->assertSet('showTokenModal', true)
        ->assertNotSet('newToken', null);

    expect($user->tokens()->where('name', 'Test Token')->exists())->toBeTrue();
});

test('displays existing tokens', function () {
    $user = User::factory()->create();
    $user->createToken('Existing Token');

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->assertSee('Existing Token');
});

test('can revoke an existing token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Token to Delete');
    $tokenId = $token->accessToken->id;

    Livewire::actingAs($user)
        ->test(ApiTokens::class)
        ->call('confirmDelete', $tokenId)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deleteTokenId', $tokenId)
        ->call('deleteToken')
        ->assertSet('showDeleteModal', false);

    expect($user->tokens()->where('id', $tokenId)->exists())->toBeFalse();
});
