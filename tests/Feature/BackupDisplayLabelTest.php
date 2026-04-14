<?php

use App\Enums\DatabaseSelectionMode;
use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\DatabaseServer;
use App\Models\Volume;

beforeEach(function () {
    $this->schedule = BackupSchedule::firstOrCreate(
        ['name' => 'Daily'],
        ['expression' => '0 2 * * *'],
    );
    $this->volume = Volume::factory()->local()->create(['name' => 'S3 Prod']);
});

test('display label with all databases and days retention', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->create([
        'volume_id' => $this->volume->id,
        'backup_schedule_id' => $this->schedule->id,
        'database_selection_mode' => DatabaseSelectionMode::All,
        'retention_policy' => 'days',
        'retention_days' => 30,
    ]);

    expect($backup->getDisplayLabel())->toBe('Daily → S3 Prod · All databases · 30d');
});

test('display label with selected databases', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->create([
        'volume_id' => $this->volume->id,
        'backup_schedule_id' => $this->schedule->id,
        'database_selection_mode' => DatabaseSelectionMode::Selected,
        'database_names' => ['app_db', 'users_db'],
        'retention_policy' => 'days',
        'retention_days' => 7,
    ]);

    expect($backup->getDisplayLabel())->toBe('Daily → S3 Prod · app_db, users_db · 7d');
});

test('display label truncates many selected databases', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->create([
        'volume_id' => $this->volume->id,
        'backup_schedule_id' => $this->schedule->id,
        'database_selection_mode' => DatabaseSelectionMode::Selected,
        'database_names' => ['app_db', 'users_db', 'logs_db'],
        'retention_policy' => 'days',
        'retention_days' => 14,
    ]);

    expect($backup->getDisplayLabel())->toBe('Daily → S3 Prod · app_db, +2 · 14d');
});

test('display label with pattern selection', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->create([
        'volume_id' => $this->volume->id,
        'backup_schedule_id' => $this->schedule->id,
        'database_selection_mode' => DatabaseSelectionMode::Pattern,
        'database_include_pattern' => '^prod_',
        'retention_policy' => 'forever',
    ]);

    expect($backup->getDisplayLabel())->toBe('Daily → S3 Prod · /^prod_/ · Forever');
});

test('display label with GFS retention', function () {
    $server = DatabaseServer::factory()->withoutBackups()->create();
    $backup = Backup::factory()->for($server)->create([
        'volume_id' => $this->volume->id,
        'backup_schedule_id' => $this->schedule->id,
        'database_selection_mode' => DatabaseSelectionMode::All,
        'retention_policy' => 'gfs',
        'retention_days' => null,
        'gfs_keep_daily' => 7,
        'gfs_keep_weekly' => 4,
        'gfs_keep_monthly' => 3,
    ]);

    expect($backup->getDisplayLabel())->toBe('Daily → S3 Prod · All databases · GFS 7d/4w/3m');
});

test('display label with sqlite paths', function () {
    $server = DatabaseServer::factory()->withoutBackups()->sqlite()->create();
    $backup = Backup::factory()->for($server)->create([
        'volume_id' => $this->volume->id,
        'backup_schedule_id' => $this->schedule->id,
        'database_selection_mode' => DatabaseSelectionMode::Selected,
        'database_names' => ['/data/app.sqlite', '/data/cache.sqlite'],
        'retention_policy' => 'days',
        'retention_days' => 30,
    ]);

    expect($backup->getDisplayLabel())->toBe('Daily → S3 Prod · app.sqlite, cache.sqlite · 30d');
});

test('display label truncates many sqlite paths', function () {
    $server = DatabaseServer::factory()->withoutBackups()->sqlite()->create();
    $backup = Backup::factory()->for($server)->create([
        'volume_id' => $this->volume->id,
        'backup_schedule_id' => $this->schedule->id,
        'database_selection_mode' => DatabaseSelectionMode::Selected,
        'database_names' => ['/data/app.sqlite', '/data/cache.sqlite', '/data/logs.sqlite'],
        'retention_policy' => 'days',
        'retention_days' => 7,
    ]);

    expect($backup->getDisplayLabel())->toBe('Daily → S3 Prod · app.sqlite, +2 · 7d');
});
