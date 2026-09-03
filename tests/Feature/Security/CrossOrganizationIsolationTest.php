<?php

use App\Http\Middleware\SetCurrentOrganization;
use App\Models\Agent;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\Organization;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\Volume;
use App\Services\CurrentOrganization;
use App\Support\BouncerScope;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Routing\SortedMiddleware;

/**
 * A real request reaches the framework with no organization resolved; the
 * suite's setupOrgContext() resolves one up front, which hides anything that
 * reads the tenant before SetCurrentOrganization has run. Clearing it here
 * makes these tests start where a request does.
 */
function asUnresolvedRequest(): void
{
    app(CurrentOrganization::class)->reset();
}

beforeEach(function () {
    $this->orgA = Organization::factory()->create(['name' => 'Org A']);
    $this->orgB = Organization::factory()->create(['name' => 'Org B']);

    // Eve holds every ability, but only inside Org B.
    $this->eve = User::factory()->create();
    $this->eve->organizations()->detach();
    attachUserToOrg($this->eve, $this->orgB, 'admin');

    $this->foreignServer = DatabaseServer::factory()->create([
        'organization_id' => $this->orgA->id,
    ]);
});

test('tenant context is resolved before route-model binding', function (string $routeName) {
    $router = app(Router::class);
    $pipeline = iterator_to_array(new SortedMiddleware(
        app(Kernel::class)->getMiddlewarePriority(),
        $router->gatherRouteMiddleware($router->getRoutes()->getByName($routeName)),
    ));

    expect(array_search(SetCurrentOrganization::class, $pipeline, true))
        ->toBeLessThan(array_search(SubstituteBindings::class, $pipeline, true));
})->with(['api.database-servers.show', 'dashboard']);

// Reading is guarded by the organization scope alone: show performs no
// authorize() call of its own.
test('a non-member cannot read another organization\'s record', function () {
    asUnresolvedRequest();

    $this->actingAs($this->eve, 'sanctum')
        ->getJson("/api/v1/database-servers/{$this->foreignServer->id}")
        ->assertNotFound();
});

// Writing goes through authorize(), so this covers the scope and the policy
// together on the path where a bypass destroys data.
test('a non-member cannot delete another organization\'s record', function () {
    $volume = Volume::factory()->create(['organization_id' => $this->orgA->id]);

    asUnresolvedRequest();

    $this->actingAs($this->eve, 'sanctum')
        ->deleteJson("/api/v1/volumes/{$volume->id}")
        ->assertNotFound();

    expect(Volume::withoutGlobalScopes()->find($volume->id))->not->toBeNull();
});

// Snapshots own no organization_id and are scoped through their server by
// DatabaseServerOrganizationScope, a separate implementation.
test('a non-member cannot read a record scoped through its server', function () {
    $snapshot = Snapshot::factory()
        ->forServer($this->foreignServer)
        ->onVolumes(Volume::factory()->create(['organization_id' => $this->orgA->id]))
        ->create();

    asUnresolvedRequest();

    $this->actingAs($this->eve, 'sanctum')
        ->getJson("/api/v1/snapshots/{$snapshot->id}")
        ->assertNotFound();
});

describe('policy ownership guard', function () {
    beforeEach(function () {
        // Eve is scoped to her own org, where she holds every ability.
        app(CurrentOrganization::class)->set($this->orgB);
        BouncerScope::apply($this->orgB->id);
    });

    test('an ability does not authorize a model from another organization', function () {
        $foreign = [
            $this->foreignServer,
            Volume::factory()->create(['organization_id' => $this->orgA->id]),
            Agent::factory()->create(['organization_id' => $this->orgA->id]),
            DatabaseServerSshConfig::factory()->create(['organization_id' => $this->orgA->id]),
        ];

        foreach ($foreign as $model) {
            expect($this->eve->can('view', $model))->toBeFalse()
                ->and($this->eve->can('update', $model))->toBeFalse()
                ->and($this->eve->can('delete', $model))->toBeFalse();
        }
    });

    test('the same ability still authorizes a model in the current organization', function () {
        $own = [
            DatabaseServer::factory()->create(['organization_id' => $this->orgB->id]),
            Volume::factory()->create(['organization_id' => $this->orgB->id]),
            Agent::factory()->create(['organization_id' => $this->orgB->id]),
            DatabaseServerSshConfig::factory()->create(['organization_id' => $this->orgB->id]),
        ];

        foreach ($own as $model) {
            expect($this->eve->can('view', $model))->toBeTrue()
                ->and($this->eve->can('update', $model))->toBeTrue()
                ->and($this->eve->can('delete', $model))->toBeTrue();
        }
    });
});
