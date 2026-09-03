<?php

use App\Models\Agent;
use App\Models\DatabaseServer;
use App\Models\DatabaseServerSshConfig;
use App\Models\Organization;
use App\Models\Snapshot;
use App\Models\User;
use App\Models\Volume;
use App\Services\CurrentOrganization;
use Illuminate\Routing\Middleware\SubstituteBindings;

/**
 * A real request reaches the framework with no organization resolved; the
 * suite's setupOrgContext() resolves one up front, which hides anything that
 * reads the tenant before SetCurrentOrganization has run. Clearing it here
 * makes these tests exercise the same starting state as production.
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
        'name' => 'org-a-db',
        'organization_id' => $this->orgA->id,
    ]);
});

test('tenant context is resolved before route-model binding', function () {
    $priority = app(Illuminate\Contracts\Http\Kernel::class)->getMiddlewarePriority();

    expect(array_search(App\Http\Middleware\SetCurrentOrganization::class, $priority, true))
        ->toBeLessThan(array_search(SubstituteBindings::class, $priority, true));

    expect(array_search(App\Http\Middleware\ScopeBouncer::class, $priority, true))
        ->toBeLessThan(array_search(SubstituteBindings::class, $priority, true));
});

describe('database servers', function () {
    test('a non-member cannot read another organization\'s server', function () {
        asUnresolvedRequest();

        $this->actingAs($this->eve, 'sanctum')
            ->getJson("/api/v1/database-servers/{$this->foreignServer->id}")
            ->assertNotFound();
    });

    test('a non-member cannot update another organization\'s server', function () {
        asUnresolvedRequest();

        $this->actingAs($this->eve, 'sanctum')
            ->putJson("/api/v1/database-servers/{$this->foreignServer->id}", [
                'name' => 'PWNED',
                'database_type' => 'sqlite',
                'backups_enabled' => false,
                'backups' => [],
            ])
            ->assertNotFound();

        expect($this->foreignServer->fresh()->name)->toBe('org-a-db');
    });

    test('a non-member cannot delete another organization\'s server', function () {
        asUnresolvedRequest();

        $this->actingAs($this->eve, 'sanctum')
            ->deleteJson("/api/v1/database-servers/{$this->foreignServer->id}")
            ->assertNotFound();

        expect(DatabaseServer::withoutGlobalScopes()->find($this->foreignServer->id))->not->toBeNull();
    });

    test('a non-member cannot trigger a backup on another organization\'s server', function () {
        asUnresolvedRequest();

        $this->actingAs($this->eve, 'sanctum')
            ->postJson("/api/v1/database-servers/{$this->foreignServer->id}/backup")
            ->assertNotFound();
    });
});

describe('volumes', function () {
    test('a non-member cannot read another organization\'s volume', function () {
        $volume = Volume::factory()->create(['organization_id' => $this->orgA->id]);

        asUnresolvedRequest();

        $this->actingAs($this->eve, 'sanctum')
            ->getJson("/api/v1/volumes/{$volume->id}")
            ->assertNotFound();
    });

    test('a non-member cannot delete another organization\'s volume', function () {
        $volume = Volume::factory()->create(['organization_id' => $this->orgA->id]);

        asUnresolvedRequest();

        $this->actingAs($this->eve, 'sanctum')
            ->deleteJson("/api/v1/volumes/{$volume->id}")
            ->assertNotFound();

        expect(Volume::withoutGlobalScopes()->find($volume->id))->not->toBeNull();
    });
});

describe('records inheriting their tenant from a server', function () {
    test('a non-member cannot read another organization\'s snapshot', function () {
        $snapshot = Snapshot::factory()
            ->forServer($this->foreignServer)
            ->onVolumes(Volume::factory()->create(['organization_id' => $this->orgA->id]))
            ->create();

        asUnresolvedRequest();

        $this->actingAs($this->eve, 'sanctum')
            ->getJson("/api/v1/snapshots/{$snapshot->id}")
            ->assertNotFound();
    });

    test('a non-member cannot read another organization\'s ssh config', function () {
        $sshConfig = DatabaseServerSshConfig::factory()->create([
            'organization_id' => $this->orgA->id,
        ]);

        asUnresolvedRequest();

        $this->actingAs($this->eve, 'sanctum')
            ->getJson("/api/v1/database-server-ssh-configs/{$sshConfig->id}")
            ->assertNotFound();
    });
});

describe('policy ownership guard', function () {
    test('abilities do not authorize a model from another organization', function () {
        $volume = Volume::factory()->create(['organization_id' => $this->orgA->id]);
        $agent = Agent::factory()->create(['organization_id' => $this->orgA->id]);

        // Eve is scoped to her own org, but holds every ability there.
        app(CurrentOrganization::class)->set($this->orgB);
        App\Support\BouncerScope::apply($this->orgB->id);

        expect($this->eve->can('view', $this->foreignServer))->toBeFalse()
            ->and($this->eve->can('update', $this->foreignServer))->toBeFalse()
            ->and($this->eve->can('delete', $this->foreignServer))->toBeFalse()
            ->and($this->eve->can('view', $volume))->toBeFalse()
            ->and($this->eve->can('delete', $volume))->toBeFalse()
            ->and($this->eve->can('view', $agent))->toBeFalse()
            ->and($this->eve->can('delete', $agent))->toBeFalse();
    });

    test('the same abilities still authorize a model in the current organization', function () {
        $ownServer = DatabaseServer::factory()->create(['organization_id' => $this->orgB->id]);
        $ownVolume = Volume::factory()->create(['organization_id' => $this->orgB->id]);

        app(CurrentOrganization::class)->set($this->orgB);
        App\Support\BouncerScope::apply($this->orgB->id);

        expect($this->eve->can('view', $ownServer))->toBeTrue()
            ->and($this->eve->can('update', $ownServer))->toBeTrue()
            ->and($this->eve->can('delete', $ownServer))->toBeTrue()
            ->and($this->eve->can('view', $ownVolume))->toBeTrue()
            ->and($this->eve->can('delete', $ownVolume))->toBeTrue();
    });
});
