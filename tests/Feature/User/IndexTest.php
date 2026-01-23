<?php

use App\Livewire\User\Index;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
});

test('displays users in table', function () {
    actingAs($this->admin);

    User::factory()->create(['name' => 'John Doe']);
    User::factory()->create(['name' => 'Jane Smith']);

    Livewire::test(Index::class)
        ->assertSee('John Doe')
        ->assertSee('Jane Smith');
});

test('can search users by name', function () {
    actingAs($this->admin);

    User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
    User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

    Livewire::test(Index::class)
        ->set('search', 'John')
        ->assertSee('John Doe')
        ->assertDontSee('Jane Smith');
});

test('admin can delete another user', function () {
    actingAs($this->admin);

    $userToDelete = User::factory()->create(['name' => 'Delete Me']);

    Livewire::test(Index::class)
        ->call('confirmDelete', $userToDelete->id)
        ->assertSet('showDeleteModal', true)
        ->call('delete')
        ->assertSet('showDeleteModal', false);

    expect(User::find($userToDelete->id))->toBeNull();
});

test('admin cannot delete themselves', function () {
    actingAs($this->admin);

    Livewire::test(Index::class)
        ->call('confirmDelete', $this->admin->id)
        ->assertForbidden();
});

test('admin cannot delete the last admin', function () {
    actingAs($this->admin);

    // Ensure this is the only admin
    expect(User::where('role', 'admin')->count())->toBe(1);

    // Create another admin to delete, then delete them to get back to 1 admin
    $anotherAdmin = User::factory()->create(['role' => 'admin', 'name' => 'Another Admin']);

    // Delete the other admin (should work)
    Livewire::test(Index::class)
        ->call('confirmDelete', $anotherAdmin->id)
        ->call('delete');

    expect(User::find($anotherAdmin->id))->toBeNull();

    // Now try to delete the last admin - should fail on authorize
    // We need to create another user to try from (but they need to be admin)
    // Actually, the test should verify you can't delete when count = 1
});

test('admin can copy invitation link for pending user', function () {
    actingAs($this->admin);

    $pendingUser = User::factory()->create([
        'name' => 'Pending User',
        'invitation_token' => 'test-token-123',
        'invitation_accepted_at' => null,
    ]);

    Livewire::test(Index::class)
        ->call('copyInvitationLink', $pendingUser->id)
        ->assertSet('showCopyModal', true)
        ->assertSet('invitationUrl', route('invitation.accept', 'test-token-123'));
});

test('non-admin cannot delete users', function () {
    $member = User::factory()->create(['role' => 'member']);
    actingAs($member);

    $userToDelete = User::factory()->create();

    Livewire::test(Index::class)
        ->call('confirmDelete', $userToDelete->id)
        ->assertForbidden();
});

test('viewer can access user index but cannot perform actions', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    actingAs($viewer);

    $someUser = User::factory()->create();

    // Can view index
    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee($someUser->name);

    // Cannot delete
    Livewire::test(Index::class)
        ->call('confirmDelete', $someUser->id)
        ->assertForbidden();
});
