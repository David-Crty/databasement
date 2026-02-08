<?php

use App\Facades\AppConfig;
use App\Jobs\VerifySnapshotFileJob;
use App\Models\DatabaseServer;
use App\Models\Snapshot;
use App\Notifications\SnapshotsMissingNotification;
use App\Services\Backup\BackupJobFactory;
use App\Services\Backup\Filesystems\FilesystemProvider;
use Illuminate\Support\Facades\Notification;
use League\Flysystem\Filesystem;

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

    (new VerifySnapshotFileJob($snapshot->id))->handle($mockProvider, app(\App\Services\FailureNotificationService::class));

    $snapshot->refresh();
    expect($snapshot->file_exists)->toBeTrue()
        ->and($snapshot->file_verified_at)->not->toBeNull();
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

    (new VerifySnapshotFileJob($snapshot->id))->handle($mockProvider, app(\App\Services\FailureNotificationService::class));

    $snapshot->refresh();
    expect($snapshot->file_exists)->toBeFalse()
        ->and($snapshot->file_verified_at)->not->toBeNull();
});

test('handles filesystem errors gracefully without changing file_exists', function () {
    $snapshot = Snapshot::factory()->create(['file_exists' => true]);

    $mockProvider = Mockery::mock(FilesystemProvider::class);
    $mockProvider->shouldReceive('getForVolume')
        ->once()
        ->andThrow(new \Exception('Connection timeout'));

    (new VerifySnapshotFileJob($snapshot->id))->handle($mockProvider, app(\App\Services\FailureNotificationService::class));

    $snapshot->refresh();
    expect($snapshot->file_exists)->toBeTrue()
        ->and($snapshot->file_verified_at)->not->toBeNull();
});

test('skips gracefully when snapshot does not exist', function () {
    $mockProvider = Mockery::mock(FilesystemProvider::class);
    $mockProvider->shouldNotReceive('getForVolume');

    (new VerifySnapshotFileJob('non-existent-id'))->handle($mockProvider, app(\App\Services\FailureNotificationService::class));
});

test('verifies all completed snapshots when no snapshotId provided', function () {
    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['db1', 'db2']]);
    $snapshots = $factory->createSnapshots($server, 'manual');

    // Set filenames and mark completed
    foreach ($snapshots as $snapshot) {
        $snapshot->update(['filename' => fake()->slug().'.sql.gz']);
        $snapshot->job->markCompleted();
    }

    // Create a snapshot with no filename — should be skipped
    $skippedSnapshots = $factory->createSnapshots($server, 'manual');
    $skippedSnapshots[0]->job->markCompleted();

    $mockFilesystem = Mockery::mock(Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->times(2)->andReturn(true);

    $mockProvider = Mockery::mock(FilesystemProvider::class);
    $mockProvider->shouldReceive('getForVolume')->times(2)->andReturn($mockFilesystem);

    (new VerifySnapshotFileJob)->handle($mockProvider, app(\App\Services\FailureNotificationService::class));

    foreach ($snapshots as $snapshot) {
        $snapshot->refresh();
        expect($snapshot->file_exists)->toBeTrue()
            ->and($snapshot->file_verified_at)->not->toBeNull();
    }

    // Skipped snapshot should remain unverified
    $skippedSnapshots[0]->refresh();
    expect($skippedSnapshots[0]->file_verified_at)->toBeNull();
});

test('sends notification when newly missing files are detected in bulk mode', function () {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', 'admin@example.com');

    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['prod_db']]);
    $snapshots = $factory->createSnapshots($server, 'manual');
    $snapshot = $snapshots[0];
    $snapshot->update(['filename' => 'backup.sql.gz', 'file_exists' => true]);
    $snapshot->job->markCompleted();

    $mockFilesystem = Mockery::mock(Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->once()->andReturn(false);

    $mockProvider = Mockery::mock(FilesystemProvider::class);
    $mockProvider->shouldReceive('getForVolume')->once()->andReturn($mockFilesystem);

    (new VerifySnapshotFileJob)->handle($mockProvider, app(\App\Services\FailureNotificationService::class));

    Notification::assertSentOnDemand(
        SnapshotsMissingNotification::class,
        fn ($notification) => $notification->missingSnapshots->count() === 1
            && $notification->missingSnapshots->first()['filename'] === 'backup.sql.gz'
    );
});

test('does not send notification when no new files are missing', function () {
    AppConfig::set('notifications.enabled', true);
    AppConfig::set('notifications.mail.to', 'admin@example.com');

    $factory = app(BackupJobFactory::class);

    $server = DatabaseServer::factory()->create(['database_names' => ['prod_db']]);
    $snapshots = $factory->createSnapshots($server, 'manual');
    $snapshot = $snapshots[0];
    // Already marked as missing — not newly missing
    $snapshot->update(['filename' => 'backup.sql.gz', 'file_exists' => false]);
    $snapshot->job->markCompleted();

    $mockFilesystem = Mockery::mock(Filesystem::class);
    $mockFilesystem->shouldReceive('fileExists')->once()->andReturn(false);

    $mockProvider = Mockery::mock(FilesystemProvider::class);
    $mockProvider->shouldReceive('getForVolume')->once()->andReturn($mockFilesystem);

    (new VerifySnapshotFileJob)->handle($mockProvider, app(\App\Services\FailureNotificationService::class));

    Notification::assertNothingSent();
});
