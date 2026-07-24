<?php

use App\Enums\Ability;
use App\Livewire\Agent\Create;
use App\Livewire\Agent\Edit;
use App\Livewire\Agent\Index;
use App\Models\Agent;
use App\Models\DatabaseServer;
use App\Models\User;
use Livewire\Livewire;

describe('agent index', function () {
    test('any member can list agents without an ability', function () {
        $user = User::factory()->withAbilities([])->create();
        Agent::factory()->create(['name' => 'Production Agent']);
        Agent::factory()->create(['name' => 'Staging Agent']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertOk()
            ->assertSee('Production Agent')
            ->assertSee('Staging Agent')
            ->set('search', 'Production')
            ->assertSee('Production Agent')
            ->assertDontSee('Staging Agent');
    });

    test('manage-agents allows deleting an agent', function () {
        $user = User::factory()->withAbilities([Ability::ManageAgents->value])->create();
        $agent = Agent::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $agent->id)
            ->assertSet('showDeleteModal', true)
            ->call('delete');

        $this->assertDatabaseMissing('agents', ['id' => $agent->id]);
    });

    test('deleting agent with servers shows warning count', function () {
        $user = User::factory()->withAbilities([Ability::ManageAgents->value])->create();
        $agent = Agent::factory()->create();
        DatabaseServer::factory()->create(['agent_id' => $agent->id]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $agent->id)
            ->assertSet('deleteServerCount', 1);
    });

    test('without manage-agents cannot delete an agent', function () {
        $user = User::factory()->withAllAbilitiesExcept(Ability::ManageAgents->value)->create();
        $agent = Agent::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('confirmDelete', $agent->id)
            ->assertForbidden();
    });
});

describe('agent creation', function () {
    test('manage-agents allows creating an agent with a token', function () {
        $user = User::factory()->withAbilities([Ability::ManageAgents->value])->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('form.name', 'My Agent')
            ->call('save')
            ->assertSet('showTokenModal', true)
            ->assertNotSet('newToken', null)
            ->call('closeTokenModal')
            ->assertRedirect(route('agents.index'));

        $agent = Agent::where('name', 'My Agent')->first();
        expect($agent)->not->toBeNull()
            ->and($agent->tokens)->toHaveCount(1);
    });

    test('without manage-agents cannot open the create screen', function () {
        $user = User::factory()->withAllAbilitiesExcept(Ability::ManageAgents->value)->create();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->assertForbidden();
    });
});

describe('agent editing', function () {
    test('manage-agents allows editing an agent name', function () {
        $user = User::factory()->withAbilities([Ability::ManageAgents->value])->create();
        $agent = Agent::factory()->create(['name' => 'Old Name']);

        Livewire::actingAs($user)
            ->test(Edit::class, ['agent' => $agent])
            ->set('form.name', 'New Name')
            ->call('save');

        expect($agent->fresh()->name)->toBe('New Name');
    });

    test('manage-agents allows regenerating a token', function () {
        $user = User::factory()->withAbilities([Ability::ManageAgents->value])->create();
        $agent = Agent::factory()->create();
        $agent->createToken('agent');

        Livewire::actingAs($user)
            ->test(Edit::class, ['agent' => $agent])
            ->call('regenerateToken')
            ->assertSet('showTokenModal', true)
            ->assertNotSet('newToken', null)
            ->call('closeTokenModal')
            ->assertSet('showTokenModal', false)
            ->assertSet('newToken', null);

        expect($agent->fresh()->tokens)->toHaveCount(1);
    });

    test('without manage-agents cannot edit an agent', function () {
        $user = User::factory()->withAllAbilitiesExcept(Ability::ManageAgents->value)->create();
        $agent = Agent::factory()->create();

        Livewire::actingAs($user)
            ->test(Edit::class, ['agent' => $agent])
            ->assertForbidden();
    });
});
