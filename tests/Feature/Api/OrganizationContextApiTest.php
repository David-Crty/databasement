<?php

use App\Models\DatabaseServer;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->defaultOrg = Organization::default();
    $this->otherOrg = Organization::factory()->create(['name' => 'Other Org']);
});

describe('org_id query parameter', function () {
    test('defaults to default org when no org_id is provided', function () {
        $user = User::factory()->create();

        DatabaseServer::factory()->create(['name' => 'Main Server', 'organization_id' => $this->defaultOrg->id]);
        DatabaseServer::factory()->create(['name' => 'Other Server', 'organization_id' => $this->otherOrg->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/database-servers');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Main Server');
    });

    test('org_id scopes resources to the specified organization', function () {
        $user = User::factory()->superAdmin()->create();

        DatabaseServer::factory()->create(['name' => 'Main Server', 'organization_id' => $this->defaultOrg->id]);
        DatabaseServer::factory()->create(['name' => 'Other Server', 'organization_id' => $this->otherOrg->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/database-servers?org_id={$this->otherOrg->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Other Server');
    });

    test('returns 403 when org_id points to an inaccessible organization', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/database-servers?org_id={$this->otherOrg->id}")
            ->assertForbidden();
    });

    test('returns 403 when org_id is a nonexistent id', function () {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/database-servers?org_id=nonexistent-ulid')
            ->assertForbidden();
    });

    test('X-Organization-Id header works like org_id parameter', function () {
        $user = User::factory()->superAdmin()->create();

        DatabaseServer::factory()->create(['name' => 'Other Server', 'organization_id' => $this->otherOrg->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/database-servers', ['X-Organization-Id' => $this->otherOrg->id]);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Other Server');
    });
});

describe('cross-org resource isolation', function () {
    test('user cannot see database servers from another organization', function () {
        $user = User::factory()->create();

        DatabaseServer::factory()->create(['name' => 'My Server', 'organization_id' => $this->defaultOrg->id]);
        DatabaseServer::factory()->create(['name' => 'Their Server', 'organization_id' => $this->otherOrg->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/database-servers');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'My Server');
    });

    test('user cannot access a specific resource from another organization', function () {
        $user = User::factory()->create();

        $otherServer = DatabaseServer::factory()->create(['organization_id' => $this->otherOrg->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/database-servers/{$otherServer->id}")
            ->assertNotFound();
    });

    test('super admin can access any organization via org_id', function () {
        $superAdmin = User::factory()->superAdmin()->create();

        DatabaseServer::factory()->create(['name' => 'Other Server', 'organization_id' => $this->otherOrg->id]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/v1/database-servers?org_id={$this->otherOrg->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Other Server');
    });

    test('member of multiple orgs can switch between them', function () {
        $user = User::factory()->create();
        attachUserToOrg($user, $this->otherOrg, 'member');

        DatabaseServer::factory()->create(['name' => 'Main Server', 'organization_id' => $this->defaultOrg->id]);
        DatabaseServer::factory()->create(['name' => 'Other Server', 'organization_id' => $this->otherOrg->id]);

        // Default: default org
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/database-servers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Main Server');

        // Explicit: other org
        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/database-servers?org_id={$this->otherOrg->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Other Server');
    });
});
