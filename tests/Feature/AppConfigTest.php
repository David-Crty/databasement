<?php

use App\Facades\AppConfig;
use App\Models\AppConfig as AppConfigModel;
use Illuminate\Support\Facades\Crypt;

test('default values exist after migration', function () {
    expect(AppConfigModel::find('backup.working_directory'))->not->toBeNull()
        ->and(AppConfigModel::find('backup.compression'))->not->toBeNull()
        ->and(AppConfigModel::find('notifications.enabled'))->not->toBeNull();
});

test('get returns correctly casted values', function () {
    expect(AppConfig::get('backup.compression'))->toBeString()
        ->and(AppConfig::get('backup.compression_level'))->toBeInt()
        ->and(AppConfig::get('backup.verify_files'))->toBeBool()
        ->and(AppConfig::get('notifications.enabled'))->toBeBool();
});

test('get returns default values', function () {
    expect(AppConfig::get('backup.working_directory'))->toBe('/tmp/backups')
        ->and(AppConfig::get('backup.compression'))->toBe('gzip')
        ->and(AppConfig::get('backup.compression_level'))->toBe(6)
        ->and(AppConfig::get('backup.job_timeout'))->toBe(7200)
        ->and(AppConfig::get('backup.job_tries'))->toBe(3)
        ->and(AppConfig::get('backup.job_backoff'))->toBe(60)
        ->and(AppConfig::get('backup.daily_cron'))->toBe('0 2 * * *')
        ->and(AppConfig::get('backup.weekly_cron'))->toBe('0 3 * * 0')
        ->and(AppConfig::get('backup.cleanup_cron'))->toBe('0 4 * * *')
        ->and(AppConfig::get('backup.verify_files'))->toBeTrue()
        ->and(AppConfig::get('backup.verify_files_cron'))->toBe('0 5 * * *')
        ->and(AppConfig::get('notifications.enabled'))->toBeFalse();
});

test('set persists and updates cache', function () {
    AppConfig::set('backup.compression', 'zstd');

    expect(AppConfig::get('backup.compression'))->toBe('zstd');

    // Verify DB was updated
    $row = AppConfigModel::find('backup.compression');
    expect($row->value)->toBe('zstd');
});

test('set persists boolean values', function () {
    AppConfig::set('notifications.enabled', true);

    expect(AppConfig::get('notifications.enabled'))->toBeTrue();

    // Verify DB stores as string
    $row = AppConfigModel::find('notifications.enabled');
    expect($row->value)->toBe('1');

    // Verify false round-trip
    AppConfig::set('notifications.enabled', false);
    expect(AppConfig::get('notifications.enabled'))->toBeFalse();

    $row->refresh();
    expect($row->value)->toBe('0');
});

test('set persists integer values', function () {
    AppConfig::set('backup.job_timeout', 3600);

    expect(AppConfig::get('backup.job_timeout'))->toBe(3600);
});

test('sensitive values are encrypted in DB and decrypted by get', function () {
    AppConfig::set('notifications.slack.webhook_url', 'https://hooks.slack.com/test');

    // Verify it's encrypted in DB
    $row = AppConfigModel::find('notifications.slack.webhook_url');
    expect($row->value)->not->toBe('https://hooks.slack.com/test')
        ->and(Crypt::decryptString($row->value))->toBe('https://hooks.slack.com/test');

    // Verify get decrypts
    expect(AppConfig::get('notifications.slack.webhook_url'))->toBe('https://hooks.slack.com/test');
});

test('set handles null for nullable fields', function () {
    AppConfig::set('notifications.mail.to', null);

    expect(AppConfig::get('notifications.mail.to'))->toBeNull();

    $row = AppConfigModel::find('notifications.mail.to');
    expect($row->value)->toBeNull();
});

test('flush clears cache', function () {
    // Prime cache
    AppConfig::get('backup.compression');

    // Update DB directly (bypassing cache)
    AppConfigModel::where('id', 'backup.compression')->update(['value' => 'zstd']);

    // Cache still returns old value
    expect(AppConfig::get('backup.compression'))->toBe('gzip');

    // After flush, returns updated value
    AppConfig::flush();
    expect(AppConfig::get('backup.compression'))->toBe('zstd');
});

test('get returns explicit default when row is missing', function () {
    AppConfigModel::where('id', 'backup.compression')->delete();
    AppConfig::flush();

    expect(AppConfig::get('backup.compression', 'gzip'))->toBe('gzip');
});

test('get falls back to CONFIG defaults when row is missing', function () {
    AppConfigModel::where('id', 'backup.compression')->delete();
    AppConfig::flush();

    expect(AppConfig::get('backup.compression'))->toBe('gzip');
});

test('set throws on unknown config key', function () {
    AppConfig::set('nonexistent.key', 'value');
})->throws(InvalidArgumentException::class, 'Unknown config key [nonexistent.key]');
