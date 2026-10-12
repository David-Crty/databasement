<?php

use App\Actions\Snapshot\VerifySnapshotFilesAction;
use App\Enums\SnapshotFileStatus;
use App\Models\DatabaseServer;
use App\Models\NotificationChannel;
use App\Models\Snapshot;
use App\Notifications\SnapshotsMissingNotification;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Filesystems\FilesystemProvider;
use Illuminate\Support\Facades\Notification;
use League\Flysystem\Filesystem;

function verifyWith(FilesystemProvider $provider): int
{
    app()->instance(FilesystemProvider::class, $provider);

    return VerifySnapshotFilesAction::run();
}

test('sets file_exists to true when file exists on volume', function () {
    $snapshot = Snapshot::factory()->create();

    $mockFilesystem = Mockery::mock(Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')
        ->with($snapshot->filename)
        ->once()
        ->andReturn(true);

    $mockProvider = Mockery::mock(FilesystemProvider::class);
    $mockProvider->shouldReceive('getForVolume')
        ->once()
        ->andReturn($mockFilesystem);

    verifyWith($mockProvider);

    $snapshot->refresh();
    expect($snapshot->hasExistingFile())->toBeTrue()
        ->and($snapshot->lastVerifiedAt())->not->toBeNull();
});

test('sets file_exists to false when file is missing from volume', function () {
    $snapshot = Snapshot::factory()->create();

    $mockFilesystem = Mockery::mock(Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')
        ->with($snapshot->filename)
        ->once()
        ->andReturn(false);

    $mockProvider = Mockery::mock(FilesystemProvider::class);
    $mockProvider->shouldReceive('getForVolume')
        ->once()
        ->andReturn($mockFilesystem);

    verifyWith($mockProvider);

    $snapshot->refresh();
    expect($snapshot->hasExistingFile())->toBeFalse()
        ->and($snapshot->lastVerifiedAt())->not->toBeNull();
});

test('handles filesystem errors gracefully without changing file_exists', function () {
    $snapshot = Snapshot::factory()->fileVerified()->create();

    $mockProvider = Mockery::mock(FilesystemProvider::class);
    $mockProvider->shouldReceive('getForVolume')
        ->once()
        ->andThrow(new \Exception('Connection timeout'));

    verifyWith($mockProvider);

    $snapshot->refresh();
    expect($snapshot->hasExistingFile())->toBeTrue()
        ->and($snapshot->lastVerifiedAt())->not->toBeNull();
});

test('verifies all completed snapshots', function () {
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['db1', 'db2']]);
    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual');

    // Set filenames and mark completed
    foreach ($snapshots as $snapshot) {
        $snapshot->update(['filename' => fake()->slug().'.sql.gz']);
        $snapshot->files()->update(['status' => SnapshotFileStatus::Completed]);
        $snapshot->job->markCompleted();
    }

    // Create a snapshot with no filename — should be skipped
    $skippedSnapshot = pendingSnapshot($server);
    $skippedSnapshot->job->markCompleted();

    $mockFilesystem = Mockery::mock(Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->times(2)->andReturn(true);

    $mockProvider = Mockery::mock(FilesystemProvider::class);
    $mockProvider->shouldReceive('getForVolume')->times(2)->andReturn($mockFilesystem);

    verifyWith($mockProvider);

    foreach ($snapshots as $snapshot) {
        $snapshot->refresh();
        expect($snapshot->hasExistingFile())->toBeTrue()
            ->and($snapshot->lastVerifiedAt())->not->toBeNull();
    }

    // Skipped snapshot should remain unverified
    $skippedSnapshot->refresh();
    expect($skippedSnapshot->lastVerifiedAt())->toBeNull();
});

test('sends notification when newly missing files are detected in bulk mode', function () {
    NotificationChannel::factory()->email()->create();

    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['prod_db']]);
    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual');
    $snapshot = $snapshots[0];
    $snapshot->update(['filename' => 'backup.sql.gz']);
    $snapshot->files()->update(['status' => SnapshotFileStatus::Completed]);
    $snapshot->job->markCompleted();

    $mockFilesystem = Mockery::mock(Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->once()->andReturn(false);

    $mockProvider = Mockery::mock(FilesystemProvider::class);
    $mockProvider->shouldReceive('getForVolume')->once()->andReturn($mockFilesystem);

    expect(verifyWith($mockProvider))->toBe(1);

    Notification::assertSentTimes(SnapshotsMissingNotification::class, 1);
});

test('does not send notification when no new files are missing', function () {
    NotificationChannel::factory()->email()->create();

    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['prod_db']]);
    $snapshots = $factory->createSnapshots($server->backups->first(), 'manual');
    $snapshot = $snapshots[0];
    // Already marked as missing — not newly missing
    $snapshot->update(['filename' => 'backup.sql.gz']);
    $snapshot->files()->update(['status' => SnapshotFileStatus::Completed, 'file_exists' => false]);
    $snapshot->job->markCompleted();

    $mockFilesystem = Mockery::mock(Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->once()->andReturn(false);

    $mockProvider = Mockery::mock(FilesystemProvider::class);
    $mockProvider->shouldReceive('getForVolume')->once()->andReturn($mockFilesystem);

    verifyWith($mockProvider);

    Notification::assertNothingSent();
});
