<?php

use App\Models\DatabaseServer;
use App\Models\Snapshot;

function runReattachOrphanedSnapshotsMigration(): void
{
    $migration = require database_path('migrations/2026_10_11_052027_reattach_orphaned_snapshots_to_single_backup.php');
    $migration->up();
}

test('orphaned snapshots are linked to their server\'s only backup configuration', function () {
    $server = DatabaseServer::factory()->create();
    $backup = $server->backups()->sole();
    $orphan = Snapshot::factory()->forServer($server)->create(['backup_id' => null]);

    runReattachOrphanedSnapshotsMigration();

    expect($orphan->refresh()->backup_id)->toBe($backup->id);
});

test('orphaned snapshots stay orphaned when their server has several backup configurations', function () {
    $server = DatabaseServer::factory()->withBackups(2)->create();
    $orphan = Snapshot::factory()->forServer($server)->create(['backup_id' => null]);

    runReattachOrphanedSnapshotsMigration();

    expect($orphan->refresh()->backup_id)->toBeNull();
});
