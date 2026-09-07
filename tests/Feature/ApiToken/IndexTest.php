<?php

use App\Enums\Ability;
use App\Livewire\ApiToken\Index;
use App\Models\User;
use Livewire\Livewire;

test('can create a new api token and use it to authenticate', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->set('tokenName', 'Test Token')
        ->call('createToken');

    $token = $user->tokens()->where('name', 'Test Token')->firstOrFail();
    expect($token->can('*'))->toBeTrue();

    // Use the created token to call the API
    $plainTextToken = $component->get('newToken');

    $this->withHeader('Authorization', 'Bearer '.$plainTextToken)
        ->getJson(route('api.database-servers.index'))
        ->assertOk();
});

test('can create a scoped api token limited to the selected abilities', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('tokenName', 'Scoped Token')
        ->set('fullAccess', false)
        ->set('tokenAbilities', [Ability::RunBackups->value, Ability::DownloadSnapshots->value])
        ->call('createToken');

    $token = $user->tokens()->where('name', 'Scoped Token')->firstOrFail();

    expect($token->can('*'))->toBeFalse()
        ->and($token->can(Ability::RunBackups->value))->toBeTrue()
        ->and($token->can(Ability::DownloadSnapshots->value))->toBeTrue()
        ->and($token->can(Ability::ManageVolumes->value))->toBeFalse();
});

test('creating a scoped token rejects abilities outside the catalogue', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('tokenName', 'Bad Token')
        ->set('fullAccess', false)
        ->set('tokenAbilities', ['not-a-real-ability'])
        ->call('createToken')
        ->assertHasErrors(['tokenAbilities.0']);

    expect($user->tokens()->where('name', 'Bad Token')->exists())->toBeFalse();
});

test('accessLabel reports full access or the ability count per token, not just anywhere on the page', function () {
    $user = User::factory()->create();
    $legacyToken = $user->createToken('Legacy Token')->accessToken;
    $scopedToken = $user->createToken('Scoped Token', [Ability::RunBackups->value, Ability::DownloadSnapshots->value])->accessToken;

    $component = Livewire::actingAs($user)->test(Index::class)->instance();

    expect($component->accessLabel($legacyToken))->toBe(__('Full access'))
        ->and($component->accessLabel($scopedToken))->toBe('2 abilities');
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
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $token = $owner->createToken('Owner Token');
    $tokenId = $token->accessToken->id;

    // A non-super-admin trying to delete another user's token should fail (scoped out)
    Livewire::actingAs($otherUser)
        ->test(Index::class)
        ->call('confirmDelete', $tokenId)
        ->call('deleteToken');

    // Token should still exist
    expect($owner->tokens()->where('id', $tokenId)->exists())->toBeTrue();
});

test('super admin can revoke any users token', function () {
    $owner = User::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $token = $owner->createToken('Owner Token');
    $tokenId = $token->accessToken->id;

    Livewire::actingAs($superAdmin)
        ->test(Index::class)
        ->call('confirmDelete', $tokenId)
        ->call('deleteToken');

    expect($owner->tokens()->where('id', $tokenId)->exists())->toBeFalse();
});

test('super admin sees all tokens with user info', function () {
    $superAdmin = User::factory()->superAdmin()->create(['name' => 'Alice']);
    $member = User::factory()->create(['name' => 'Bob']);
    $superAdmin->createToken('Alice Token');
    $member->createToken('Bob Token');

    Livewire::actingAs($superAdmin)
        ->test(Index::class)
        ->assertSee('Alice Token')
        ->assertSee('Bob Token')
        ->assertSee('Alice')
        ->assertSee('Bob')
        ->assertSee('Admin')
        ->assertSee('Viewer');
});

test('org admin only sees own tokens', function () {
    // Org admins are not privileged here — only super admins see every token.
    $orgAdmin = User::factory()->create(['name' => 'Alice', 'role' => 'admin']);
    $other = User::factory()->create(['name' => 'Bob']);
    $orgAdmin->createToken('Alice Token');
    $other->createToken('Bob Token');

    Livewire::actingAs($orgAdmin)
        ->test(Index::class)
        ->assertSee('Alice Token')
        ->assertDontSee('Bob Token');
});
