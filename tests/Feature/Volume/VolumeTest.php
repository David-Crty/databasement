<?php

use App\Livewire\Volume\Create;
use App\Livewire\Volume\Edit;
use App\Livewire\Volume\Index;
use App\Models\DatabaseServer;
use App\Models\User;
use App\Models\Volume;
use App\Services\Backup\BackupJobFactory;
use Livewire\Livewire;

// Authorization Tests
test('guests cannot access volume pages', function () {
    $this->get(route('volumes.index'))->assertRedirect(route('login'));
    $this->get(route('volumes.create'))->assertRedirect(route('login'));
});

test('authenticated users can access volume index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('volumes.index'))
        ->assertStatus(200);
});

// Create Tests - Local Type
test('can create local volume with valid data', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'Local Backup Storage')
        ->set('form.type', 'local')
        ->set('form.path', '/var/backups')
        ->call('save')
        ->assertRedirect(route('volumes.index'));

    $this->assertDatabaseHas('volumes', [
        'name' => 'Local Backup Storage',
        'type' => 'local',
    ]);

    $volume = Volume::where('name', 'Local Backup Storage')->first();
    expect($volume->config)->toEqual(['path' => '/var/backups']);
});

// Create Tests - S3 Type
test('can create s3 volume with valid data', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.name', 'S3 Backup Bucket')
        ->set('form.type', 's3')
        ->set('form.bucket', 'my-backup-bucket')
        ->set('form.prefix', 'backups/production/')
        ->call('save')
        ->assertRedirect(route('volumes.index'));

    $this->assertDatabaseHas('volumes', [
        'name' => 'S3 Backup Bucket',
        'type' => 's3',
    ]);

    $volume = Volume::where('name', 'S3 Backup Bucket')->first();
    expect($volume->config)->toEqual([
        'bucket' => 'my-backup-bucket',
        'prefix' => 'backups/production/',
    ]);
});

// Edit Tests
test('can edit local volume', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Original Name',
        'type' => 'local',
        'config' => ['path' => '/old/path'],
    ]);

    Livewire::actingAs($user)
        ->test(Edit::class, ['volume' => $volume])
        ->set('form.name', 'Updated Name')
        ->set('form.path', '/new/path')
        ->call('save')
        ->assertRedirect(route('volumes.index'));

    $volume->refresh();
    expect($volume->name)->toBe('Updated Name');
    expect($volume->config)->toEqual(['path' => '/new/path']);
});

test('can edit s3 volume', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'S3 Volume',
        'type' => 's3',
        'config' => ['bucket' => 'old-bucket', 'prefix' => 'old/'],
    ]);

    Livewire::actingAs($user)
        ->test(Edit::class, ['volume' => $volume])
        ->set('form.bucket', 'new-bucket')
        ->set('form.prefix', 'new/')
        ->call('save')
        ->assertRedirect(route('volumes.index'));

    $volume->refresh();
    expect($volume->config)->toEqual(['bucket' => 'new-bucket', 'prefix' => 'new/']);
});

// List Tests
test('displays volumes in index', function () {
    $user = User::factory()->create();
    Volume::create([
        'name' => 'Local Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);
    Volume::create([
        'name' => 'S3 Volume',
        'type' => 's3',
        'config' => ['bucket' => 'my-bucket', 'prefix' => ''],
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee('Local Volume')
        ->assertSee('S3 Volume')
        ->assertSee('/var/backups')
        ->assertSee('my-bucket');
});

test('can search volumes', function () {
    $user = User::factory()->create();
    Volume::create([
        'name' => 'Production Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);
    Volume::create([
        'name' => 'Development Volume',
        'type' => 's3',
        'config' => ['bucket' => 'dev-bucket', 'prefix' => ''],
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->set('search', 'Production')
        ->assertSee('Production Volume')
        ->assertDontSee('Development Volume');
});

// Delete Tests
test('can delete volume', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Volume to Delete',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('confirmDelete', $volume->id)
        ->assertSet('deleteId', $volume->id)
        ->call('delete')
        ->assertSet('deleteId', null);

    $this->assertDatabaseMissing('volumes', [
        'id' => $volume->id,
    ]);
});

// Immutability Tests
test('volume with snapshots only allows name editing', function () {
    $user = User::factory()->create();

    // Create a volume with a snapshot
    $server = DatabaseServer::factory()->create(['database_names' => ['testdb']]);
    $volume = $server->backup->volume;

    $factory = app(BackupJobFactory::class);
    $factory->createSnapshots($server, 'manual');

    // Verify volume now has snapshots
    expect($volume->hasSnapshots())->toBeTrue();

    $originalPath = $volume->config['path'];

    // Can access edit page with hasSnapshots flag set
    Livewire::actingAs($user)
        ->test(Edit::class, ['volume' => $volume])
        ->assertSuccessful()
        ->assertSet('hasSnapshots', true)
        ->assertSet('form.name', $volume->name)
        // Can update name
        ->set('form.name', 'Updated Volume Name')
        ->call('save')
        ->assertRedirect(route('volumes.index'));

    // Verify only name was updated, config unchanged
    $volume->refresh();
    expect($volume->name)->toBe('Updated Volume Name');
    expect($volume->config['path'])->toBe($originalPath);
});

test('can edit volume without snapshots', function () {
    $user = User::factory()->create();
    $volume = Volume::create([
        'name' => 'Empty Volume',
        'type' => 'local',
        'config' => ['path' => '/var/backups'],
    ]);

    // Verify volume has no snapshots
    expect($volume->hasSnapshots())->toBeFalse();

    // Should be able to access edit page and form is populated
    Livewire::actingAs($user)
        ->test(Edit::class, ['volume' => $volume])
        ->assertSuccessful()
        ->assertSet('form.name', 'Empty Volume')
        ->assertSet('form.type', 'local');
});

// Connection Test Tests
test('can test local volume connection', function () {
    $user = User::factory()->create();
    $tempDir = sys_get_temp_dir().'/volume-test-'.uniqid();
    mkdir($tempDir, 0755, true);

    Livewire::actingAs($user)
        ->test(Create::class)
        ->set('form.type', 'local')
        ->set('form.path', $tempDir)
        ->call('testConnection')
        ->assertSet('form.connectionTestSuccess', true)
        ->assertSet('form.connectionTestMessage', 'Connection successful!');

    rmdir($tempDir);
});
