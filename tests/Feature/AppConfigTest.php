<?php

use App\Facades\AppConfig;
use App\Models\AppConfig as AppConfigModel;

test('default values exist after migration', function () {
    expect(AppConfigModel::find('backup.working_directory'))->not->toBeNull()
        ->and(AppConfigModel::find('backup.compression'))->not->toBeNull();
});

test('get returns correctly casted values', function () {
    expect(AppConfig::get('backup.compression'))->toBeString()
        ->and(AppConfig::get('backup.compression_level'))->toBeInt()
        ->and(AppConfig::get('backup.verify_files'))->toBeBool();
});

test('get returns default values', function () {
    expect(AppConfig::get('backup.working_directory'))->toBe('/tmp/backups')
        ->and(AppConfig::get('backup.compression'))->toBe('gzip')
        ->and(AppConfig::get('backup.compression_level'))->toBe(6)
        ->and(AppConfig::get('backup.job_timeout'))->toBe(7200)
        ->and(AppConfig::get('backup.job_tries'))->toBe(3)
        ->and(AppConfig::get('backup.job_backoff'))->toBe(60)
        ->and(AppConfig::get('backup.cleanup_cron'))->toBe('0 4 * * *')
        ->and(AppConfig::get('backup.verify_files'))->toBeTrue()
        ->and(AppConfig::get('backup.verify_files_cron'))->toBe('0 5 * * *');
});

test('set persists values', function () {
    AppConfig::set('backup.compression', 'zstd');

    expect(AppConfig::get('backup.compression'))->toBe('zstd');

    // Verify DB was updated
    $row = AppConfigModel::find('backup.compression');
    expect($row->value)->toBe('zstd');
});

test('set persists boolean values', function () {
    AppConfig::set('backup.verify_files', true);

    expect(AppConfig::get('backup.verify_files'))->toBeTrue();

    // Verify DB stores as string
    $row = AppConfigModel::find('backup.verify_files');
    expect($row->value)->toBe('1');

    // Verify false round-trip
    AppConfig::set('backup.verify_files', false);
    expect(AppConfig::get('backup.verify_files'))->toBeFalse();

    $row->refresh();
    expect($row->value)->toBe('0');
});

test('set persists integer values', function () {
    AppConfig::set('backup.job_timeout', 3600);

    expect(AppConfig::get('backup.job_timeout'))->toBe(3600);
});

test('get reads fresh values from database', function () {
    AppConfig::get('backup.compression');

    // Update DB directly
    AppConfigModel::where('id', 'backup.compression')->update(['value' => 'zstd']);

    // Returns updated value immediately
    expect(AppConfig::get('backup.compression'))->toBe('zstd');
});

test('get returns explicit default when row is missing', function () {
    AppConfigModel::where('id', 'backup.compression')->delete();

    expect(AppConfig::get('backup.compression', 'gzip'))->toBe('gzip');
});

test('get falls back to CONFIG defaults when row is missing', function () {
    AppConfigModel::where('id', 'backup.compression')->delete();

    expect(AppConfig::get('backup.compression'))->toBe('gzip');
});

test('set throws on unknown config key', function () {
    AppConfig::set('nonexistent.key', 'value');
})->throws(InvalidArgumentException::class, 'Unknown config key [nonexistent.key]');
