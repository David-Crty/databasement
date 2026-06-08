<?php

use App\Facades\AppConfig;
use App\Models\BackupJob;
use App\Models\Snapshot;

test('fails backup jobs stuck in running state beyond timeout', function () {
    AppConfig::set('backup.job_timeout', 3600);

    $job = BackupJob::create([
        'status' => 'running',
        'started_at' => now()->subSeconds(3600 + 300 + 1), // beyond timeout + 5min grace
    ]);
    Snapshot::factory()->create(['backup_job_id' => $job->id]);

    $this->artisan('jobs:recover-stuck')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe('failed')
        ->and($job->error_message)->toContain('stuck in running state');
});

test('fails backup jobs stuck in pending state beyond timeout', function () {
    AppConfig::set('backup.job_timeout', 3600);

    $job = BackupJob::create(['status' => 'pending']);
    BackupJob::where('id', $job->id)->toBase()->update(['created_at' => now()->subSeconds(3600 + 300 + 1)]);
    Snapshot::factory()->create(['backup_job_id' => $job->id]);

    $this->artisan('jobs:recover-stuck')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe('failed')
        ->and($job->error_message)->toContain('stuck in pending state');
});

test('does not touch running backup jobs within timeout', function () {
    AppConfig::set('backup.job_timeout', 3600);

    $job = BackupJob::create([
        'status' => 'running',
        'started_at' => now()->subSeconds(3600),
    ]);
    Snapshot::factory()->create(['backup_job_id' => $job->id]);

    $this->artisan('jobs:recover-stuck')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe('running');
});

test('does not touch pending backup jobs within timeout', function () {
    AppConfig::set('backup.job_timeout', 3600);

    $job = BackupJob::create(['status' => 'pending']);
    Snapshot::factory()->create(['backup_job_id' => $job->id]);

    $this->artisan('jobs:recover-stuck')
        ->assertExitCode(0);

    $job->refresh();
    expect($job->status)->toBe('pending');
});
