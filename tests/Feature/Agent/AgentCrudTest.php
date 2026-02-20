<?php

use App\Livewire\Agent\Create;
use App\Livewire\Agent\Edit;
use App\Livewire\Agent\Index;
use App\Models\Agent;
use App\Models\DatabaseServer;
use App\Models\User;
use Livewire\Livewire;

describe('agent index', function () {
    test('authenticated users can view agents list', function () {
        $user = User::factory()->create();
        Agent::factory()->count(3)->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertOk()
            ->assertViewHas('agents');
    });

    test('agents list shows agent names', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['name' => 'Production Agent']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Production Agent');
    });

    test('can search agents by name', function () {
        $user = User::factory()->create();
        Agent::factory()->create(['name' => 'Production Agent']);
        Agent::factory()->create(['name' => 'Staging Agent']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'Production')
            ->assertSee('Production Agent')
            ->assertDontSee('Staging Agent');
    });

    test('can delete an agent', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $agent->id)
            ->assertSet('showDeleteModal', true)
            ->call('delete');

        $this->assertDatabaseMissing('agents', ['id' => $agent->id]);
    });

    test('deleting agent with servers shows warning count', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        DatabaseServer::factory()->create(['agent_id' => $agent->id]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $agent->id)
            ->assertSet('deleteServerCount', 1);
    });

    test('viewers cannot delete agents', function () {
        $user = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $agent = Agent::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $agent->id)
            ->assertForbidden();
    });
});

describe('agent creation', function () {
    test('can create an agent and receive token', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('form.name', 'My Agent')
            ->call('save')
            ->assertSet('showTokenModal', true)
            ->assertNotSet('newToken', null);

        $this->assertDatabaseHas('agents', ['name' => 'My Agent']);
    });

    test('agent token is created on save', function () {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(Create::class)
            ->set('form.name', 'Token Agent')
            ->call('save');

        $agent = Agent::where('name', 'Token Agent')->first();
        expect($agent->tokens)->toHaveCount(1);
    });

    test('viewers cannot create agents', function () {
        $user = User::factory()->create(['role' => User::ROLE_VIEWER]);

        Livewire::actingAs($user)
            ->test(Create::class)
            ->assertForbidden();
    });

    test('name is required', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('form.name', '')
            ->call('save')
            ->assertHasErrors(['form.name']);
    });
});

describe('agent editing', function () {
    test('can edit agent name', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['name' => 'Old Name']);

        Livewire::actingAs($user)
            ->test(Edit::class, ['agent' => $agent])
            ->set('form.name', 'New Name')
            ->call('save')
            ->assertRedirect(route('agents.index'));

        expect($agent->fresh()->name)->toBe('New Name');
    });

    test('can regenerate token', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $agent->createToken('agent');

        Livewire::actingAs($user)
            ->test(Edit::class, ['agent' => $agent])
            ->call('regenerateToken')
            ->assertSet('showTokenModal', true)
            ->assertNotSet('newToken', null);

        // Old token should be revoked, new one created
        expect($agent->fresh()->tokens)->toHaveCount(1);
    });

    test('viewers cannot edit agents', function () {
        $user = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $agent = Agent::factory()->create();

        Livewire::actingAs($user)
            ->test(Edit::class, ['agent' => $agent])
            ->assertForbidden();
    });
});
